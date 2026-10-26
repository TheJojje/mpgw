(function () {
    'use strict';

    const templateId = document.getElementById('rule-summary')?.dataset.templateId;
    const normalizedTextEl = document.getElementById('normalized-text');
    const normalizedSubjectEl = document.getElementById('normalized-subject');
    const normalizedHeadersEl = document.getElementById('normalized-headers');
    const sourceLabels = { body: 'brödtext', subject: 'ämnesrad', headers: 'mail-headers' };
    function sourceTextEl(source) {
        return source === 'subject' ? normalizedSubjectEl : (source === 'headers' ? normalizedHeadersEl : normalizedTextEl);
    }
    const newRulePanel = document.getElementById('new-rule-panel');
    const newRuleTitle = document.getElementById('new-rule-title');
    const selectionPreviewWrap = document.getElementById('selection-preview-wrap');
    const selectedTextPreview = document.getElementById('selected-text-preview');
    const suggestionsEl = document.getElementById('suggestions');
    const patternInput = document.getElementById('rule-pattern');
    const liveTestResult = document.getElementById('live-test-result');
    const rawEmailPicker = document.getElementById('raw-email-picker');
    const rawEmailPaste = document.getElementById('raw-email-paste');
    const normalizeBtn = document.getElementById('normalize-btn');
    const addManuallyBtn = document.getElementById('add-rule-manually-btn');
    const saveRuleForm = document.getElementById('save-rule-form');

    let debounceHandle = null;
    let hasNormalizedText = !normalizedTextEl?.dataset.placeholder;

    // --- Stegindikator ----------------------------------------------------
    function setStep(step) {
        document.querySelectorAll('#builder-steps li').forEach((el, idx) => {
            el.classList.toggle('step-active', idx + 1 === step);
            el.classList.toggle('step-done', idx + 1 < step);
        });
    }
    setStep(hasNormalizedText ? 2 : 1);

    // --- Valj/lasa in exempelmail -------------------------------------
    // Att valja ett tidigare mottaget mail normaliserar direkt (ingen extra knapptryckning kravs).
    rawEmailPicker?.addEventListener('change', () => {
        const id = rawEmailPicker.value;
        if (!id) return;
        window.location.href = '/admin/templates/' + getTemplateIdFromUrl() + '?raw_email_id=' + encodeURIComponent(id);
    });

    normalizeBtn?.addEventListener('click', async () => {
        const raw = rawEmailPaste.value.trim();
        if (!raw) {
            rawEmailPaste.focus();
            return;
        }
        normalizeBtn.disabled = true;
        normalizeBtn.textContent = 'Normaliserar...';
        try {
            const res = await fetch('/admin/api/normalize-preview', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ raw_text: raw }),
            });
            const data = await res.json();
            normalizedTextEl.textContent = data.normalized_text;
            normalizedTextEl.dataset.rawEmailId = '';
            delete normalizedTextEl.dataset.placeholder;
            hasNormalizedText = true;
            setStep(2);
            flashHint();
        } finally {
            normalizeBtn.disabled = false;
            normalizeBtn.textContent = 'Normalisera & visa';
        }
    });

    function flashHint() {
        const hint = document.getElementById('normalized-hint');
        if (!hint) return;
        hint.classList.add('hint-flash');
        setTimeout(() => hint.classList.remove('hint-flash'), 1200);
    }

    function getTemplateIdFromUrl() {
        const parts = window.location.pathname.split('/');
        return parts[parts.indexOf('templates') + 1];
    }

    // --- Markering -> visa forslag --------------------------------------
    // Bada kalltexterna (amnesrad och brodtext) anvander samma
    // markeringsflode - vilket element markeringen skedde i avgor bara
    // vilken source ('subject'/'body') som sparas med regeln.
    [normalizedTextEl, normalizedSubjectEl, normalizedHeadersEl].forEach((el) => {
        el?.addEventListener('mouseup', () => onSelectionChange(el));
        el?.addEventListener('touchend', () => onSelectionChange(el));
    });

    function onSelectionChange(sourceEl) {
        if (!hasNormalizedText) return;

        const selection = window.getSelection();
        if (!selection || selection.isCollapsed || selection.rangeCount === 0) return;

        const range = selection.getRangeAt(0);
        if (!sourceEl.contains(range.commonAncestorContainer)) return;

        const fullText = sourceEl.textContent;
        const selectedText = selection.toString();
        if (!selectedText.trim()) return;

        // Rakna charoffset for markeringen inom hela textnoden.
        const preRange = range.cloneRange();
        preRange.selectNodeContents(sourceEl);
        preRange.setEnd(range.startContainer, range.startOffset);
        const start = preRange.toString().length;
        const end = start + selectedText.length;

        openNewRulePanelFromSelection(sourceEl, fullText, start, end, selectedText);
    }

    async function openNewRulePanelFromSelection(sourceEl, fullText, start, end, selectedText) {
        resetRuleForm();
        const source = sourceEl.dataset.source || 'body';
        newRuleTitle.textContent = 'Ny regel från markering (' + (sourceLabels[source] || 'brödtext') + ')';
        selectionPreviewWrap.hidden = false;
        newRulePanel.hidden = false;
        selectedTextPreview.textContent = selectedText;
        document.getElementById('rule-selection-start').value = start;
        document.getElementById('rule-selection-end').value = end;
        document.getElementById('rule-raw-email-id').value = normalizedTextEl.dataset.rawEmailId || '';
        document.getElementById('rule-source').value = source;
        document.getElementById('rule-source-field').hidden = true;
        setStep(3);
        newRulePanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        suggestionsEl.innerHTML = '<p class="hint">Hämtar förslag...</p>';

        const res = await fetch('/admin/api/suggest-pattern', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ full_text: fullText, selection_start: start, selection_end: end }),
        });
        const data = await res.json();

        suggestionsEl.innerHTML = '';
        (data.suggestions || []).forEach((s, idx) => {
            const div = document.createElement('div');
            div.className = 'suggestion' + (idx === 0 ? ' selected' : '');
            div.innerHTML = '<strong>' + escapeHtml(s.label) + '</strong> — ' + escapeHtml(s.description)
                + '<code>' + escapeHtml(s.pattern) + '</code>';
            div.addEventListener('click', () => {
                document.querySelectorAll('.suggestion').forEach((el) => el.classList.remove('selected'));
                div.classList.add('selected');
                patternInput.value = s.pattern;
                runLiveTest(fullText);
            });
            suggestionsEl.appendChild(div);
        });

        if (data.suggestions && data.suggestions.length > 0) {
            patternInput.value = data.suggestions[0].pattern;
        }
        runLiveTest(fullText);
    }

    // --- Lagg till regel manuellt (utan markering) -------------------------
    addManuallyBtn?.addEventListener('click', () => {
        resetRuleForm();
        newRuleTitle.textContent = 'Ny regel (manuell)';
        selectionPreviewWrap.hidden = true;
        suggestionsEl.innerHTML = '<p class="hint">Skriv fältnamn och mönster nedan för hand.</p>';
        document.getElementById('rule-raw-email-id').value = normalizedTextEl.dataset.rawEmailId || '';
        document.getElementById('rule-source-field').hidden = false;
        newRulePanel.hidden = false;
        setStep(3);
        document.getElementById('rule-field-name').focus();
        newRulePanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });

    function resetRuleForm() {
        saveRuleForm.reset();
        liveTestResult.textContent = '';
        liveTestResult.className = 'live-test-result';
        document.getElementById('rule-source-field').hidden = true;
        document.getElementById('rule-editing-id').value = '';
    }

    // Textinnehallet aktuell regel ska testas mot, baserat pa vald source.
    function currentSourceText() {
        const source = document.getElementById('rule-source').value;
        return sourceTextEl(source)?.textContent || '';
    }

    // --- Live-test vid redigering av monstret (eller vald kalla) ---------
    patternInput?.addEventListener('input', () => {
        clearTimeout(debounceHandle);
        debounceHandle = setTimeout(() => runLiveTest(currentSourceText()), 300);
    });
    document.getElementById('rule-source')?.addEventListener('change', () => runLiveTest(currentSourceText()));

    async function runLiveTest(fullText) {
        const pattern = patternInput.value;
        if (!pattern || !hasNormalizedText) {
            liveTestResult.textContent = !hasNormalizedText
                ? 'Välj eller klistra in ett mail först för att kunna testa mönstret live.'
                : '';
            liveTestResult.className = 'live-test-result';
            return;
        }
        const res = await fetch('/admin/api/test-pattern', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ pattern: pattern, text: fullText }),
        });
        const data = await res.json();

        if (data.error) {
            liveTestResult.className = 'live-test-result fail';
            liveTestResult.textContent = 'Regex-fel: ' + data.error;
        } else if (data.matched) {
            liveTestResult.className = 'live-test-result ok';
            liveTestResult.textContent = 'Träff: "' + data.value + '"';
        } else {
            liveTestResult.className = 'live-test-result fail';
            liveTestResult.textContent = 'Ingen träff mot det inlästa mailet.';
        }
    }

    // --- Avbryt ----------------------------------------------------------
    document.getElementById('cancel-rule-btn')?.addEventListener('click', () => {
        newRulePanel.hidden = true;
        resetRuleForm();
        setStep(hasNormalizedText ? 2 : 1);
    });

    // --- Spara regel (ny eller redigering) ---------------------------------
    saveRuleForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const editingRuleId = document.getElementById('rule-editing-id').value;
        const payload = {
            field_name: form.field_name.value,
            field_label: form.field_label.value,
            data_type: form.data_type.value,
            is_required: form.is_required.checked,
            pattern: form.pattern.value,
            source: form.source.value,
            sample_source_snippet: selectedTextPreview.textContent,
            csrf_token: form.csrf_token.value,
        };

        const url = editingRuleId
            ? '/admin/templates/' + getTemplateIdFromUrl() + '/rules/' + editingRuleId
            : '/admin/templates/' + getTemplateIdFromUrl() + '/rules';

        const res = await fetch(url, {
            method: editingRuleId ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });

        if (res.ok) {
            try {
                sessionStorage.setItem('mpgw-pending-toast', JSON.stringify({
                    type: 'success',
                    text: editingRuleId ? 'Regeln uppdaterades.' : 'Regeln skapades.',
                }));
            } catch (e) {}
            window.location.reload();
        } else {
            const data = await res.json().catch(() => ({}));
            window.mpgwToast('error', 'Kunde inte spara regeln: ' + (data.error || res.statusText));
        }
    });

    // --- Redigera regel -------------------------------------------------------
    document.querySelectorAll('.edit-rule-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            resetRuleForm();
            newRuleTitle.textContent = 'Redigera regel';
            selectionPreviewWrap.hidden = true;
            suggestionsEl.innerHTML = '';
            document.getElementById('rule-editing-id').value = btn.dataset.ruleId;
            document.getElementById('rule-field-name').value = btn.dataset.fieldName || '';
            document.getElementById('rule-field-label').value = btn.dataset.fieldLabel || '';
            document.getElementById('rule-data-type').value = btn.dataset.dataType || 'string';
            document.getElementById('rule-is-required').checked = btn.dataset.isRequired === '1';
            patternInput.value = btn.dataset.pattern || '';
            document.getElementById('rule-source').value = btn.dataset.source || 'body';
            document.getElementById('rule-source-field').hidden = false;
            newRulePanel.hidden = false;
            setStep(3);
            runLiveTest(currentSourceText());
            newRulePanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });

    // --- Ta bort regel -------------------------------------------------------
    document.querySelectorAll('.delete-rule-btn').forEach((btn) => {
        btn.addEventListener('click', async () => {
            if (!confirm('Ta bort den har regeln?')) return;

            const ruleId = btn.dataset.ruleId;
            const csrfToken = document.querySelector('#save-rule-form input[name="csrf_token"]').value;

            const res = await fetch('/admin/templates/' + getTemplateIdFromUrl() + '/rules/' + ruleId, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: csrfToken }),
            });

            if (res.ok) {
                try {
                    sessionStorage.setItem('mpgw-pending-toast', JSON.stringify({
                        type: 'success',
                        text: 'Regeln togs bort.',
                    }));
                } catch (e) {}
                window.location.reload();
            } else {
                window.mpgwToast('error', 'Kunde inte ta bort regeln.');
            }
        });
    });

    // --- Testa om befintliga regler vid sidladdning ------------------------
    document.querySelectorAll('.rule-result').forEach(async (el) => {
        const pattern = el.dataset.pattern;
        const source = el.closest('li')?.dataset.source || 'body';
        const fullText = sourceTextEl(source)?.textContent || '';
        if (!pattern || !hasNormalizedText) return;

        const res = await fetch('/admin/api/test-pattern', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ pattern: pattern, text: fullText }),
        });
        const data = await res.json();
        el.className = 'rule-result ' + (data.matched ? 'ok' : 'fail');
        el.textContent = data.matched ? ('✓ ' + data.value) : '✗ ingen träff mot detta mail';
    });

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // --- Tvåvägs hover-koppling: regel i listan <-> markering i källtexten ---
    // Delat data-rule-id kopplar ihop <li> i #rule-list med <mark> i #normalized-text,
    // satta server-side (se templates/edit.twig). Ingen nytt API-anrop behövs.
    function wireRuleHoverSync() {
        function setHighlight(ruleId, on) {
            document.querySelectorAll('[data-rule-id="' + CSS.escape(ruleId) + '"]').forEach((el) => {
                el.classList.toggle('hl', on);
            });
        }

        document.querySelectorAll('#rule-list [data-rule-id], #normalized-text mark[data-rule-id], #normalized-subject mark[data-rule-id]').forEach((el) => {
            const id = el.dataset.ruleId;
            if (!id) return;
            el.addEventListener('mouseenter', () => setHighlight(id, true));
            el.addEventListener('mouseleave', () => setHighlight(id, false));
            el.addEventListener('focus', () => setHighlight(id, true));
            el.addEventListener('blur', () => setHighlight(id, false));
        });
    }
    wireRuleHoverSync();
})();
