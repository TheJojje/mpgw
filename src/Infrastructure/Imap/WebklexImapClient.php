<?php

declare(strict_types=1);

namespace Mpgw\Infrastructure\Imap;

use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\FolderFetchingException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Message;

/**
 * Tunt lager ovanpa webklex/php-imap - kapslar in bibliotekets API sa
 * resten av appen bara pratar med enkla varden (array), inte bibliotekets
 * interna Attribute/Address-objekt.
 *
 * Notera: header-falt (from, to, subject, message_id, date) nas via
 * Message::get<Namn>(), som via __call() delegerar till Header::get(),
 * vilket ALLTID returnerar ett Attribute-objekt (aldrig skalarvarde
 * direkt) - darfor plockas varden ut med first()/toString()/toDate()
 * nedan istallet for att anta en returtyp.
 */
final class WebklexImapClient
{
    /**
     * Testar att en IMAP-anslutning + inloggning + mappval fungerar, utan
     * att hamta nagra meddelanden. Anvands av admin-granssnittet for att
     * verifiera uppgifter innan de sparas (se MailboxesController::testConnection).
     *
     * Kastar RuntimeException med ett anvandarvanligt svenskt meddelande
     * vid fel - bibliotekets egna undantag har ofta tomma eller kryptiska
     * meddelanden (raw errno/errstr, protokoll-dumpar) som inte gar att
     * visa direkt for en slutanvandare.
     */
    public function testConnection(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        string $folder
    ): void {
        $cm = new ClientManager();
        $client = $cm->make([
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption === 'none' ? false : $encryption,
            'validate_cert' => true,
            'username' => $username,
            'password' => $password,
            'protocol' => 'imap',
        ]);

        try {
            $client->connect();
            try {
                // Forsoker valja mappen sa att fel folder-namn ocksa fangas,
                // inte bara fel host/port/uppgifter.
                $client->getFolder($folder)->messages()->all()->limit(1);
            } finally {
                $client->disconnect();
            }
        } catch (Throwable $e) {
            throw new RuntimeException($this->describeError($e, $host, $port, $folder), 0, $e);
        }
    }

    /** Oversatter bibliotekets undantag till ett meddelande en administrator forstar. */
    private function describeError(Throwable $e, string $host, int $port, string $folder): string
    {
        if ($e instanceof AuthFailedException) {
            return 'Inloggningen nekades. Kontrollera användarnamn och lösenord.';
        }

        if ($e instanceof FolderFetchingException) {
            return sprintf('Mappen "%s" kunde inte hittas eller öppnas på servern.', $folder);
        }

        if ($e instanceof ConnectionFailedException) {
            $detail = trim((string) $e->getMessage());
            if ($detail === '' || $detail === 'connection setup failed') {
                $detail = $this->firstMeaningfulMessage($e->getPrevious());
            }

            return sprintf(
                'Kunde inte ansluta till %s:%d%s',
                $host,
                $port,
                $detail !== '' ? ' (' . $detail . ')' : '. Kontrollera host, port och kryptering.'
            );
        }

        if ($e instanceof ResponseException) {
            return 'Servern avvisade förfrågan. Kontrollera mapp och kryptering, eller försök igen senare.';
        }

        $message = trim($e->getMessage());
        return $message !== ''
            ? 'Anslutningen misslyckades: ' . $message
            : 'Anslutningen misslyckades av okänd anledning.';
    }

    private function firstMeaningfulMessage(?Throwable $e): string
    {
        while ($e !== null && trim($e->getMessage()) === '') {
            $e = $e->getPrevious();
        }

        return $e !== null ? trim($e->getMessage()) : '';
    }

    /**
     * Hamtar mail med UID storre an $sinceUid (eller alla om null) fran
     * en mailbox. UID-baserad avgransning ar robustare mot dubbletter
     * an att bara lita pa \Seen-flaggan vid upprepad polling.
     *
     * @return array<int,array{uid:string,message_id:?string,from:string,to:?string,subject:?string,date:?string,headers:string,text_body:?string,html_body:?string}>
     */
    public function fetchNew(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        string $folder,
        ?string $sinceUid
    ): array {
        $cm = new ClientManager();
        $client = $cm->make([
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption === 'none' ? false : $encryption,
            'validate_cert' => true,
            'username' => $username,
            'password' => $password,
            'protocol' => 'imap',
        ]);

        try {
            $client->connect();
            $folderObj = $client->getFolder($folder);

            $query = $folderObj->messages()->all();
            if ($sinceUid !== null && $sinceUid !== '') {
                // IMAP SEARCH UID tar ett UID-set, inte en jamforelseoperator -
                // where('UID', '>', $sinceUid) skickades tidigare med tre
                // argument dar WhereQuery::where() bara tar tva (criteria,
                // value), sa '>' hamnade som varde och $sinceUid foll bort helt.
                // Det gav ett ogiltigt sokvillkor och IMAP-felet
                // "BAD ... Invalid UID messageset". Ratt syntax for "allt efter
                // senast sedda UID" ar intervallet (uid+1):* - och det maste
                // skickas oquoterat (via CUSTOM-prefixet), for where('UID', $set)
                // hade quotat ett icke-numeriskt varde som "1236:*" vilket
                // ocksa ar ett ogiltigt UID-set.
                $uidSet = ((int) $sinceUid + 1) . ':*';
                $query = $query->where('CUSTOM UID ' . $uidSet);
            }

            $messages = $query->setFetchOrder('asc')->get();

            $result = [];
            foreach ($messages as $message) {
                /** @var Message $message */
                $result[] = $this->toArray($message);
            }

            $client->disconnect();

            return $result;
        } catch (Throwable $e) {
            // Samma lasbara oversattning som testConnection() - detta
            // meddelande hamnar i ingest_log och visas for administratoren
            // som en varning pa mailboxens redigeringssida.
            throw new RuntimeException($this->describeError($e, $host, $port, $folder), 0, $e);
        }
    }

    /** @return array{uid:string,message_id:?string,from:string,to:?string,subject:?string,date:?string,headers:string,text_body:?string,html_body:?string} */
    private function toArray(Message $message): array
    {
        $fromAddress = $this->firstAddress($message->getFrom());
        $toAddress = $this->firstAddress($message->getTo());

        $dateAttr = $message->getDate();
        $date = null;
        try {
            $date = $dateAttr instanceof Attribute && $dateAttr->count() > 0
                ? $dateAttr->toDate()->toIso8601String()
                : null;
        } catch (\Throwable) {
            $date = null; // ogiltigt/saknat Date-header ska inte fa polling att krascha
        }

        return [
            'uid' => (string) $message->getUid(),
            'message_id' => $this->attrToString($message->getMessageId()),
            'from' => $fromAddress?->mail ?? '',
            'to' => $toAddress?->mail,
            'subject' => $this->attrToString($message->getSubject()),
            'date' => $date,
            'headers' => $message->getHeader()?->raw ?? '',
            'text_body' => $message->getTextBody() ?: null,
            'html_body' => $message->getHTMLBody() ?: null,
        ];
    }

    private function firstAddress(mixed $attr): ?Address
    {
        if (!$attr instanceof Attribute || $attr->count() === 0) {
            return null;
        }

        $value = $attr->first();
        return $value instanceof Address ? $value : null;
    }

    private function attrToString(mixed $attr): ?string
    {
        if (!$attr instanceof Attribute || $attr->count() === 0) {
            return null;
        }

        return $attr->toString();
    }
}
