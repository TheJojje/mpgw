<?php

declare(strict_types=1);

namespace Mpgw\Http\View;

use Mpgw\Http\Middleware\CsrfMiddleware;
use Mpgw\Service\AuthorizationService;
use Mpgw\Support\FlashMessage;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ViewRenderer
{
    private Environment $twig;

    public function __construct(
        string $templatesPath,
        bool $debug,
        private readonly ?PDO $db = null,
        private readonly ?AuthorizationService $authz = null,
    ) {
        $loader = new FilesystemLoader($templatesPath);
        $this->twig = new Environment($loader, [
            'cache' => false, // MVP: ingen mallcache, enkel att utveckla mot
            'debug' => $debug,
        ]);

        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', [CsrfMiddleware::class, 'token']));

        // can('users.manage') -> true/false, anvands i layout.twig for att
        // dolja nav-lankar och i views for att dolja skriv-knappar for
        // anvandare utan ratt rattighet.
        $this->twig->addFunction(new \Twig\TwigFunction('can', fn (string $slug): bool => $this->authz?->can($slug) ?? false));

        // status_chip('ok'|'warn'|'danger'|'neutral', 'Etikett') -> färdig <span class="status-chip ...">
        // Håller status->variant-mappningen central istället för upprepad if/else i varje mall.
        $this->twig->addFunction(new \Twig\TwigFunction('status_chip', [self::class, 'renderStatusChip'], ['is_safe' => ['html']]));

        // rule_color(0) -> "--rule-1" osv, roterar genom den 6-färgade regel-paletten i admin.css.
        $this->twig->addFunction(new \Twig\TwigFunction('rule_color_var', [self::class, 'ruleColorVar']));

        // masked_key('mpgw_b4e2688') -> maskerat key_prefix (aldrig den hemliga
        // nyckeln - den finns inte lagrad nagonstans) med en visa/dölj-knapp,
        // samma UX-mönster som lösenordsfältens [data-toggle-pw] i layout.twig.
        // Används i logs/index.twig och logs/show.twig för att visa vilken
        // API-nyckel som låg bakom ett loggat anrop utan att lämna den synlig
        // i klartext som standard.
        $this->twig->addFunction(new \Twig\TwigFunction('masked_key', [self::class, 'renderMaskedKey'], ['is_safe' => ['html']]));

        // api_keys.scopes lagras som en JSON-array-sträng (se ApiKeysController::create) -
        // det här filtret gör den till en riktig Twig-array att loopa över.
        $this->twig->addFilter(new \Twig\TwigFilter('json_decode', static fn (?string $json) => $json !== null ? (json_decode($json, true) ?: []) : []));
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->twig->addGlobal($name, $value);
    }

    public static function renderStatusChip(string $variant, string $label): string
    {
        $allowed = ['ok', 'warn', 'danger', 'neutral'];
        if (!in_array($variant, $allowed, true)) {
            $variant = 'neutral';
        }

        return '<span class="status-chip ' . $variant . '"><span class="dot2"></span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    public static function ruleColorVar(int $index): string
    {
        $slot = ($index % 6) + 1;
        return '--rule-' . $slot;
    }

    public static function renderMaskedKey(?string $keyPrefix): string
    {
        if ($keyPrefix === null || $keyPrefix === '') {
            return '—';
        }

        $full = htmlspecialchars($keyPrefix, ENT_QUOTES, 'UTF-8');
        $masked = htmlspecialchars(substr($keyPrefix, 0, 8), ENT_QUOTES, 'UTF-8') . '****';

        return '<span class="masked-key" data-masked="' . $masked . '" data-full="' . $full . '">'
            . '<code class="masked-key-value">' . $masked . '</code>'
            . '<button type="button" class="btn btn-small masked-key-toggle" aria-label="Visa nyckel">'
            . '<svg class="ic"><use href="#ic-eye"></use></svg></button>'
            . '</span>';
    }

    public function render(Request $request, Response $response, string $template, array $data = []): Response
    {
        // current_path anvands av layout.twig for att markera aktiv lank i sidebaren.
        $data['current_path'] ??= $request->getUri()->getPath();

        // flash_messages: engangsmeddelanden satta av en controller innan en
        // redirect (skapa/andra/ta bort) - se Support\FlashMessage. Plockas
        // upp och tas bort ur sessionen har sa de bara visas en gang, som en
        // SweetAlert2-toast (layout.twig).
        $data['flash_messages'] ??= FlashMessage::consume();

        // sidebar_open_alerts_count visar en liten badge pa "Larm"-lanken i sidebaren
        // (layout.twig), oavsett vilken sida som renderas - satt centralt har istallet
        // for att varje controller ska behova rakna ut den sjalv.
        if (!array_key_exists('sidebar_open_alerts_count', $data)) {
            $data['sidebar_open_alerts_count'] = $this->db !== null
                ? (int) $this->db->query("SELECT COUNT(*) FROM alerts WHERE status = 'open'")->fetchColumn()
                : 0;
        }

        // current_user visas overst i sidebaren (namn + roll) - satt centralt
        // har, samma monster som sidebar_open_alerts_count ovan. Rollnamnen
        // hamtas fran sessionscachen (satt av AuthorizationService::primeSessionForUser
        // vid inloggning) istallet for en extra join-fraga per request.
        if (!array_key_exists('current_user', $data)) {
            $data['current_user'] = null;
            if (!empty($_SESSION['admin_user_id']) && $this->db !== null) {
                $stmt = $this->db->prepare('SELECT id, name, email FROM users WHERE id = ?');
                $stmt->execute([(int) $_SESSION['admin_user_id']]);
                $user = $stmt->fetch();
                if ($user !== false) {
                    $data['current_user'] = [
                        'id' => (int) $user['id'],
                        'name' => $user['name'],
                        'email' => $user['email'],
                        'role_names' => $_SESSION['authz_role_names'] ?? [],
                    ];
                }
            }
        }

        $html = $this->twig->render($template, $data);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
