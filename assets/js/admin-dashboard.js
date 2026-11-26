jQuery(document).ready(function($) {
    // Funktion zum Aktivieren eines Tabs ohne Scroll
    function activateTab(target, updateUrl) {
        // Tabs aktualisieren
        $('.nav-tab').removeClass('nav-tab-active');
        $(`.nav-tab[href="#${target}"]`).addClass('nav-tab-active');

        // A-02: ARIA tab-state lives here, inside the single source of truth,
        // so aria-selected/roving-tabindex can never desync from the visual
        // active class (mirrors the F-01 discipline — no parallel state path).
        $('.nav-tab').attr({ 'aria-selected': 'false', tabindex: '-1' });
        $(`.nav-tab[href="#${target}"]`).attr({ 'aria-selected': 'true', tabindex: '0' });

        // Inhalte aktualisieren
        $('.tab-content').removeClass('active');
        $('#' + target).addClass('active');

        // URL persistieren ohne Scroll — auf ?tab= konvergiert (F-02), damit
        // eine kopierte/geteilte URL über denselben ?tab=-Zweig auflöst, der
        // schon bei der Load-Time-Resolution zuerst geprüft wird. Die
        // .nav-tab href-Attribute (#hash) bleiben unangetastet.
        if (updateUrl && history.replaceState) {
            history.replaceState(null, null, window.location.pathname + '?page=altimo&tab=' + target);
        }

        // Load-on-activate: single source of truth for click, deep-link and
        // hash-restore (F-01). Deferred to the next tick because activateTab()
        // runs synchronously during load-time tab resolution — BEFORE the
        // `historyLoaded`/`statisticsLoaded`/`currentFilters`/`selectedHistoryIds`
        // `let` bindings (declared later in this same document.ready scope) are
        // initialized. A synchronous loadHistory()/loadStatistics() call here
        // would touch those bindings in their Temporal Dead Zone and throw
        // ReferenceError, aborting document.ready. The guard is re-checked
        // INSIDE the deferred callback to preserve exactly-once semantics.
        setTimeout(function () {
            if (target === 'history' && !historyLoaded) {
                loadHistory(1);
            } else if (target === 'statistics' && !statisticsLoaded) {
                loadStatistics();
            } else if (target === 'welcome' && siteProfileAutoPending) {
                runSiteProfileAuto();
            }
        }, 0);
    }

    // Tab Navigation
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();

        const $this = $(this);
        const target = $this.attr('href').substring(1);

        activateTab(target, true);
    });

    // A-02: arrow-key automatic activation (WAI-ARIA APG tab pattern). Moves
    // focus to and activates the adjacent tab (wrapping), reusing activateTab()
    // as the single source of truth for every state change.
    $('.nav-tab').on('keydown', function(e) {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') {
            return;
        }
        e.preventDefault();

        const $tabs = $('.nav-tab');
        const currentIndex = $tabs.index(this);
        const lastIndex = $tabs.length - 1;
        const nextIndex = e.key === 'ArrowRight'
            ? (currentIndex === lastIndex ? 0 : currentIndex + 1)
            : (currentIndex === 0 ? lastIndex : currentIndex - 1);

        const nextTarget = $tabs.eq(nextIndex).attr('href').substring(1);
        activateTab(nextTarget, true);
        $(`.nav-tab[href="#${nextTarget}"]`).focus();
    });

    // Initialen Tab basierend auf Query-Parameter, Hash oder Welcome setzen
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab');
    const hash = window.imageAiSeoInitialHash || '';

    if (tabParam && $('#' + tabParam).length) {
        // Tab aus Query-Parameter (z.B. nach Aktivierung) - URL bereinigen ohne Hash
        activateTab(tabParam, false);
        // Query-Parameter aus URL entfernen, nur Hash setzen
        if (history.replaceState) {
            history.replaceState(null, null, window.location.pathname + '?page=altimo#' + tabParam);
        }
    } else if (hash && $('#' + hash).length) {
        // Tab aus Hash
        activateTab(hash, false);
    } else {
        // Wenn kein gültiger Tab, Welcome-Tab aktivieren
        activateTab('welcome', false);
    }

    // API Key Validierung
    $('.validate-key').on('click', function(e) {
        e.preventDefault();

        const button = $(this);
        const $input = $('#image-ai-seo-api-key');
        const apiKey = $input.val();
        const statusElement = button.closest('.api-key-section').find('.validation-status');
        const hasStoredKey = $input.data('has-key') === 1;

        // KRITISCH: Wenn bereits ein Key gespeichert ist und das Feld noch
        // den maskierten Platzhalter enthält (Nutzer hat nichts geändert),
        // NICHT senden -- sonst überschreibt der maskierte String den echten
        // gespeicherten Key (QUAL-08, D-08).
        //
        // Stattdessen bestätigt ein Klick mit maskiertem Schlüssel nur die
        // Einwilligung (z. B. nach einem Widerruf oder wenn sich der
        // Datenschutzhinweis geändert hat): der gespeicherte Key bleibt
        // unangetastet, es wird nur der Consent erteilt.
        if (hasStoredKey && apiKey.indexOf('•') !== -1) {
            grantConsentForStoredKey(button, statusElement);
            return;
        }

        if (!apiKey) {
            statusElement
                .removeClass('success')
                .addClass('error')
                .text(imageAiSeoAdmin.strings.emptyKey);
            return;
        }
        
        // Button-Status aktualisieren
        button.prop('disabled', true);
        statusElement.html('<span class="spinner is-active"></span>');
        
        // API-Anfrage senden
        $.ajax({
            url: imageAiSeoAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'image_ai_seo_validate_api_key',
                nonce: imageAiSeoAdmin.nonce,
                api_key: apiKey,
                consent: $('#image-ai-seo-consent-checkbox').is(':checked') ? 'true' : 'false'
            },
            success: function(response) {
                if (response.success) {
                    statusElement
                        .removeClass('error')
                        .addClass('success')
                        .html('✓ ' + response.data.message);

                    // Credits aktualisieren
                    updateCreditsDisplay(response.data.credits);
                    markConsentGranted();
                    setSiteProfileAvailable(true);

                    queueSiteProfileAutoRun(response.data);
                } else {
                    statusElement
                        .removeClass('success')
                        .addClass('error')
                        .text('✕ ' + response.data.message);
                }
            },
            error: function() {
                statusElement
                    .removeClass('success')
                    .addClass('error')
                    .text(imageAiSeoAdmin.strings.connectionError);
            },
            complete: function() {
                button.prop('disabled', false);
                
                // Status nach 3 Sekunden ausblenden
                setTimeout(function() {
                    statusElement.fadeOut(function() {
                        $(this).html('').show().removeClass('success error');
                    });
                }, 3000);
            }
        });
    });

    /**
     * Erteilt die Einwilligung für einen bereits gespeicherten API-Key, ohne den
     * Key erneut zu senden (der Feldinhalt ist nur ein maskierter Platzhalter).
     */
    function grantConsentForStoredKey(button, statusElement) {
        if (!$('#image-ai-seo-consent-checkbox').is(':checked')) {
            statusElement
                .removeClass('success')
                .addClass('error')
                .text(imageAiSeoAdmin.strings.consentCheckboxRequired);
            return;
        }

        button.prop('disabled', true);
        statusElement.html('<span class="spinner is-active"></span>');

        $.ajax({
            url: imageAiSeoAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'image_ai_seo_grant_consent',
                nonce: imageAiSeoAdmin.nonce,
                consent: 'true'
            },
            success: function(response) {
                if (response.success) {
                    statusElement
                        .removeClass('error')
                        .addClass('success')
                        .html('✓ ' + response.data.message);
                    markConsentGranted();
                    setSiteProfileAvailable(true);
                    queueSiteProfileAutoRun(response.data);
                } else {
                    statusElement
                        .removeClass('success')
                        .addClass('error')
                        .text('✕ ' + response.data.message);
                }
            },
            error: function() {
                statusElement
                    .removeClass('success')
                    .addClass('error')
                    .text(imageAiSeoAdmin.strings.connectionError);
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    }

    /**
     * Spiegelt eine erteilte Einwilligung in der Oberfläche: Hinweise auf die
     * fehlende oder veraltete Einwilligung verschwinden, der Widerrufen-Link
     * erscheint (er wird serverseitig nur bei erteilter Einwilligung gerendert).
     */
    function markConsentGranted() {
        const $section = $('.consent-section');

        $section.find('.image-ai-seo-reconsent-hint, .image-ai-seo-consent-hint').remove();
        $('.image-ai-seo-consent-stop-notice').remove();

        if (!$section.find('.revoke-consent').length) {
            $('<button type="button" class="button-link revoke-consent"></button>')
                .text(imageAiSeoAdmin.strings.revokeConsent)
                .appendTo($section);
        }

        $section.find('.revoke-consent').show();
    }

    // Consent widerrufen (D-06). Delegiert, weil markConsentGranted() den
    // Button nachträglich einfügen kann.
    $(document).on('click', '.revoke-consent', function(e) {
        e.preventDefault();

        const button = $(this);
        const statusElement = button.closest('.api-key-section').find('.validation-status');

        button.prop('disabled', true);
        statusElement.html('<span class="spinner is-active"></span>');

        $.ajax({
            url: imageAiSeoAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'image_ai_seo_revoke_consent',
                nonce: imageAiSeoAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    statusElement
                        .removeClass('error')
                        .addClass('success')
                        .html('✓ ' + response.data.message);

                    $('#image-ai-seo-consent-checkbox').prop('checked', false);
                    button.hide();
                    setSiteProfileAvailable(false);
                } else {
                    statusElement
                        .removeClass('success')
                        .addClass('error')
                        .text('✕ ' + response.data.message);
                }
            },
            error: function() {
                statusElement
                    .removeClass('success')
                    .addClass('error')
                    .text(imageAiSeoAdmin.strings.connectionError);
            },
            complete: function() {
                button.prop('disabled', false);

                setTimeout(function() {
                    statusElement.fadeOut(function() {
                        $(this).html('').show().removeClass('success error');
                    });
                }, 3000);
            }
        });
    });

    // Settings speichern
    $('.save-settings').on('click', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const statusElement = $('.settings-status');
        
        // Button-Status aktualisieren
        button.prop('disabled', true);
        statusElement.html('<span class="spinner is-active"></span>');
        
        const data = {
            action: 'image_ai_seo_save_settings',
            nonce: imageAiSeoAdmin.nonce,
            auto_optimize: $('#settings input[name="auto_optimize"]').is(':checked').toString(),
            language: $('#settings select[name="ai_language"]').val()
        };
        
        // AJAX-Anfrage senden
        $.ajax({
            url: imageAiSeoAdmin.ajaxurl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    statusElement
                        .removeClass('error')
                        .addClass('success')
                        .html('✓ ' + response.data.message);
                        
                    // Aktualisiere den Checkbox-Status
                    $('#settings input[name="auto_optimize"]').prop('checked', response.data.auto_optimize);
                } else {
                    statusElement
                        .removeClass('success')
                        .addClass('error')
                        .text('✕ ' + response.data.message);
                }
            },
            error: function() {
                statusElement
                    .removeClass('success')
                    .addClass('error')
                    .text(imageAiSeoAdmin.strings.connectionError);
            },
            complete: function() {
                button.prop('disabled', false);
                
                // Status nach 3 Sekunden ausblenden
                setTimeout(function() {
                    statusElement.fadeOut(function() {
                        $(this).html('').show().removeClass('success error');
                    });
                }, 3000);
            }
        });
    });

    // Zeichenzähler für Textfelder
    function updateCharCounter(input) {
        const maxLength = $(input).attr('maxlength');
        const currentLength = $(input).val().length;
        const counter = $(input).siblings('.char-counter');
        
        counter.text(currentLength + '/' + maxLength);
        
        if (currentLength >= maxLength) {
            counter.addClass('limit-reached');
        } else {
            counter.removeClass('limit-reached');
        }
    }

    // Initialisiere Zeichenzähler
    $('.input-wrapper input[type="text"], .input-wrapper textarea').each(function() {
        updateCharCounter(this);
    }).on('input', function() {
        updateCharCounter(this);
    });

    // Welcome Settings speichern
    $('.save-welcome-settings').on('click', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const statusElement = button.siblings('.settings-status');
        
        // Button-Status aktualisieren
        button.prop('disabled', true);
        statusElement.html('<span class="spinner is-active"></span>');
        
        const data = {
            action: 'image_ai_seo_save_welcome_settings',
            nonce: imageAiSeoAdmin.nonce,
            main_keyword: $('#main-keyword').val(),
            additional_keywords: $('#additional-keywords').val(),
            website_description: $('#website-description').val()
        };
        
        // AJAX-Anfrage senden
        $.ajax({
            url: imageAiSeoAdmin.ajaxurl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    statusElement
                        .removeClass('error')
                        .addClass('success')
                        .html('✓ ' + response.data.message);
                } else {
                    statusElement
                        .removeClass('success')
                        .addClass('error')
                        .text('✕ ' + response.data.message);
                }
            },
            error: function() {
                statusElement
                    .removeClass('success')
                    .addClass('error')
                    .text(imageAiSeoAdmin.strings.connectionError);
            },
            complete: function() {
                button.prop('disabled', false);
                
                // Status nach 3 Sekunden ausblenden
                setTimeout(function() {
                    statusElement.fadeOut(function() {
                        $(this).html('').show().removeClass('success error');
                    });
                }, 3000);
            }
        });
    });

    // =====================================================================
    // AI suggestion of the Welcome settings (2.2.0)
    // =====================================================================
    //
    // Two entry points, one AJAX action (image_ai_seo_suggest_site_profile); the request
    // to the service runs in PHP, so the API key never reaches the browser.
    // - Automatic, once: after the first API-key validation (or on the next dashboard visit
    //   if that page was closed) the Welcome tab fills its EMPTY fields and the server saves
    //   them right away. The server remembers the run, so it never starts again on its own.
    // - "Suggest with AI": fills all three fields in the form (asking first if they already
    //   contain text); nothing is saved until the user clicks "Save Settings".

    const siteProfileFields = {
        main_keyword: '#main-keyword',
        additional_keywords: '#additional-keywords',
        website_description: '#website-description'
    };
    let siteProfileAutoPending = !!(imageAiSeoAdmin.siteProfile && imageAiSeoAdmin.siteProfile.autoRun);
    let siteProfileBusy = false;

    /**
     * Shows a state in the status area below the button: 'busy' (spinner), 'success',
     * 'error', or '' to clear it. Text is always inserted as text, never as HTML: server
     * messages are shown verbatim.
     */
    function showSiteProfileStatus(type, message) {
        const $status = $('.image-ai-seo-site-profile-status');

        $status.empty().removeClass('success error busy');

        if (!type) {
            return;
        }

        $status.addClass(type);

        if (type === 'busy') {
            $status.append('<span class="spinner is-active"></span>');
        }

        $('<span class="image-ai-seo-site-profile-message"></span>').text(message).appendTo($status);
    }

    /**
     * Locks the Welcome form while a suggestion is being requested, so nothing typed in
     * the meantime is overwritten or saved half-way.
     */
    function setSiteProfileBusy(busy) {
        siteProfileBusy = busy;

        $('.keyword-section').attr('aria-busy', busy ? 'true' : 'false');
        $.each(siteProfileFields, function(field, selector) {
            $(selector).prop('readonly', busy);
        });
        $('.save-welcome-settings').prop('disabled', busy);
        $('.suggest-site-profile').prop('disabled', busy || $('.suggest-site-profile').data('unavailable') === true);
    }

    /**
     * Enables the button once a key and consent exist, or disables it and shows the hint
     * pointing to the License tab.
     */
    function setSiteProfileAvailable(available) {
        const $button = $('.suggest-site-profile');

        $button.data('unavailable', !available).prop('disabled', !available || siteProfileBusy);
        $('.image-ai-seo-site-profile-hint').prop('hidden', available);
    }

    /**
     * 2.2.0: the first key validation, or the first consent for an already stored key,
     * queues the one-time AI suggestion of the Welcome settings. Show the success message
     * briefly, then open the Welcome tab, whose activation starts the run.
     */
    function queueSiteProfileAutoRun(data) {
        if (!data || !data.site_profile_auto_run) {
            return;
        }

        siteProfileAutoPending = true;
        setTimeout(function() {
            activateTab('welcome', true);
        }, 1200);
    }

    /**
     * Writes values into the form fields and refreshes their character counters.
     */
    function fillSiteProfileFields(values, onlyFields) {
        $.each(siteProfileFields, function(field, selector) {
            if (onlyFields && onlyFields.indexOf(field) === -1) {
                return;
            }
            if (typeof values[field] !== 'string' || values[field] === '') {
                return;
            }
            $(selector).val(values[field]);
            updateCharCounter($(selector)[0]);
        });
    }

    function siteProfileErrorMessage(response) {
        return (response && response.data && response.data.message) ? response.data.message : imageAiSeoAdmin.strings.connectionError;
    }

    /**
     * The one-time automatic run. Claimed client-side first, so switching tabs while it
     * runs cannot start it twice; the server enforces the same once more.
     */
    function runSiteProfileAuto() {
        if (!siteProfileAutoPending || siteProfileBusy) {
            return;
        }
        siteProfileAutoPending = false;

        setSiteProfileBusy(true);
        showSiteProfileStatus('busy', imageAiSeoAdmin.strings.siteProfileAnalysing);

        $.ajax({
            url: imageAiSeoAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'image_ai_seo_suggest_site_profile',
                nonce: imageAiSeoAdmin.nonce,
                mode: 'auto'
            },
            success: function(response) {
                if (response.success) {
                    const filled = response.data.filled || [];
                    fillSiteProfileFields(response.data.values || {}, filled);
                    showSiteProfileStatus(filled.length ? 'success' : '', response.data.message);
                    return;
                }

                // Nothing queued or not ready (yet): nothing to tell the user.
                const code = response.data && response.data.code;
                if (code === 'image_ai_seo_auto_run_not_pending' || code === 'image_ai_seo_auto_run_not_ready') {
                    showSiteProfileStatus('', '');
                    return;
                }

                showSiteProfileStatus('error', siteProfileErrorMessage(response));
            },
            error: function() {
                showSiteProfileStatus('error', imageAiSeoAdmin.strings.connectionError);
            },
            complete: function() {
                setSiteProfileBusy(false);
            }
        });
    }

    /**
     * "Suggest with AI": requests a suggestion and fills all three fields; the user saves.
     */
    function requestSiteProfileSuggestion() {
        setSiteProfileBusy(true);
        showSiteProfileStatus('busy', imageAiSeoAdmin.strings.siteProfileAnalysing);

        $.ajax({
            url: imageAiSeoAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'image_ai_seo_suggest_site_profile',
                nonce: imageAiSeoAdmin.nonce,
                mode: 'manual'
            },
            success: function(response) {
                if (response.success) {
                    // A manual request also used up a queued automatic run (server side).
                    siteProfileAutoPending = false;
                    fillSiteProfileFields(response.data.suggestions || {}, null);
                    showSiteProfileStatus('success', imageAiSeoAdmin.strings.siteProfileInserted);
                    $('#main-keyword').trigger('focus');
                } else {
                    showSiteProfileStatus('error', siteProfileErrorMessage(response));
                }
            },
            error: function() {
                showSiteProfileStatus('error', imageAiSeoAdmin.strings.connectionError);
            },
            complete: function() {
                setSiteProfileBusy(false);
            }
        });
    }

    /**
     * Inline confirmation before suggestions replace text the user already entered, built
     * like the bulk resume and pre-flight prompts (notice with a button pair).
     */
    function showSiteProfileOverwritePrompt() {
        $('.image-ai-seo-site-profile-prompt').remove();

        const prompt = $('<div class="image-ai-seo-site-profile-prompt notice notice-warning inline"></div>');
        $('<p></p>').text(imageAiSeoAdmin.strings.siteProfileOverwrite).appendTo(prompt);

        const actions = $('<p class="image-ai-seo-site-profile-prompt-actions"></p>').appendTo(prompt);
        const replaceButton = $('<button type="button" class="button button-primary site-profile-replace"></button>')
            .text(imageAiSeoAdmin.strings.siteProfileReplace)
            .appendTo(actions);
        const keepButton = $('<button type="button" class="button site-profile-keep"></button>')
            .text(imageAiSeoAdmin.strings.siteProfileKeep)
            .appendTo(actions);

        replaceButton.on('click', function() {
            prompt.remove();
            requestSiteProfileSuggestion();
        });

        keepButton.on('click', function() {
            prompt.remove();
            $('.suggest-site-profile').trigger('focus');
        });

        prompt.insertAfter($('.suggest-site-profile'));
        replaceButton.trigger('focus');
    }

    $('.suggest-site-profile').on('click', function(e) {
        e.preventDefault();

        if (siteProfileBusy) {
            return;
        }

        showSiteProfileStatus('', '');

        let hasText = false;
        $.each(siteProfileFields, function(field, selector) {
            if ($.trim($(selector).val() || '') !== '') {
                hasText = true;
            }
        });

        // Ask before anything is requested: declining costs no site analysis.
        if (hasText) {
            showSiteProfileOverwritePrompt();
            return;
        }

        requestSiteProfileSuggestion();
    });

    // Initial state from the server-rendered markup (disabled = no key or no consent).
    $('.suggest-site-profile').data('unavailable', $('.suggest-site-profile').is(':disabled'));

    // A run that is still due starts from activateTab() as soon as the Welcome tab is
    // shown, on load (the default tab) or later; see the deferred block there.

    // Bulk Optimization
    let isOptimizing = false;
    let currentImageIndex = 0;
    let imagesToOptimize = [];
    let progressContainer = null;

    // D-14: Zustand des laufenden Blocks. `currentRunId` verbindet jeden Einzelaufruf mit
    // dem serverseitig persistierten Lauf, `bulkTotal`/`bulkDoneCount` beziehen sich immer
    // auf den GANZEN Lauf -- nach einem Resume ist `imagesToOptimize` nur noch der Rest,
    // die Anzeige soll aber weiterhin "Bild 7 von 20" sagen und nicht wieder bei 1 anfangen.
    let currentRunId = null;
    let bulkTotal = 0;
    let bulkDoneCount = 0;
    let pendingRunIds = null;
    let bulkNotices = [];

    // CR-05: IDs, die der Server nach MAX_ATTEMPTS ausgemustert hat. Sie werden am Ende des
    // Laufs benannt, statt bei jedem Seitenaufruf still erneut versucht zu werden.
    let bulkFailedIds = [];

    // D-08: Zeitpunkt der letzten frischen Credit-Abfrage. Ein ~60s-Cache verhindert,
    // dass Tab-Wechsel/Fokus-Events das Backend mit refresh_credit_balance-Aufrufen
    // überfluten, während die Anzeige trotzdem beim Laden/Zurückkehren aktuell wird.
    let lastCreditFetchAt = 0;

    // Eine Statuszeile, die den ganzen Lauf über stehen bleibt. Der frühere Resume-Hinweis
    // wurde im selben synchronen Ablauf von optimizeNextImage() überschrieben und war damit
    // nie lesbar; die Fortschrittszeile darunter zählt weiter, diese Zeile nicht.
    let bulkStatusLine = null;

    /**
     * Erzeugt eine Lauf-Kennung. Kleinbuchstaben, Ziffern und Bindestriche -- mehr überlebt
     * sanitize_key() auf der Serverseite nicht.
     */
    function generateRunId() {
        return 'run-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
    }

    function buildProgressUi(anchor, statusMessage) {
        $('.optimization-progress-wrap').remove();
        $('.bulk-run-status').remove();

        bulkStatusLine = null;

        if (statusMessage) {
            // Bewusst eine EIGENE Zeile AUSSERHALB von .optimization-progress: der Balken ist
            // 20px hoch mit overflow:hidden, und die Zeile soll bis zum Ende des Laufs stehen
            // bleiben statt von der Bild-für-Bild-Zählung überschrieben zu werden.
            bulkStatusLine = $('<div class="bulk-run-status"></div>')
                .text(statusMessage)
                .insertAfter(anchor);
        }

        // The text goes BELOW the bar, not into it: .optimization-progress is 20px high
        // with overflow:hidden and clipped the "Optimizing image X of Y" line completely.
        // progressContainer is the wrapper, so every progressContainer.remove() takes
        // the bar and the text away together.
        progressContainer = $('<div class="optimization-progress-wrap"></div>')
            .insertAfter(bulkStatusLine || anchor);
        const track = $('<div class="optimization-progress"></div>').appendTo(progressContainer);

        return {
            progressBar: $('<div class="progress-bar"></div>').appendTo(track),
            progressText: $('<div class="progress-text" aria-live="polite"></div>').appendTo(progressContainer)
        };
    }

    $('.start-bulk-optimization').on('click', async function(e) {
        e.preventDefault();

        const button = $(this);
        if (isOptimizing || button.prop('disabled')) {
            return;
        }

        // Status setzen
        isOptimizing = true;
        button.prop('disabled', true);
        button.text(imageAiSeoAdmin.strings.optimizing);

        try {
            // Hole die Liste der nicht optimierten Bilder
            const response = await $.ajax({
                url: imageAiSeoAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'image_ai_seo_get_unoptimized_images',
                    nonce: imageAiSeoAdmin.nonce
                }
            });

            if (!response.success || !response.data.image_ids.length) {
                throw new Error(imageAiSeoAdmin.strings.noImagesToOptimize);
            }

            imagesToOptimize = response.data.image_ids;
            currentImageIndex = 0;

            // D-10/CRED-03: Vor dem Start prüfen, ob die Auswahl die bekannte Credit-Bilanz
            // übersteigt. Ist das der Fall (und sind überhaupt Credits vorhanden), wird der
            // Lauf NICHT einfach gestartet und mittendrin abgebrochen, sondern der Nutzer wird
            // gefragt und darf so viele Bilder wie möglich optimieren. Bei 0 Credits wird
            // bewusst NICHT abgeschnitten: der Lauf startet, der serverseitige Credit-Gate
            // liefert den no_credits-Code, und die Upsell-CTA erscheint statt einer nackten
            // Fehlermeldung.
            const availableCredits = parseInt($('.remaining-credits').data('credits') || 0, 10);
            if (availableCredits > 0 && availableCredits < imagesToOptimize.length) {
                const truncated = await showBulkPreflightPrompt(button, availableCredits, imagesToOptimize);
                if (truncated === false) {
                    // Abbruch: kein Lauf startet, keine Credits werden verbraucht.
                    return;
                }
                imagesToOptimize = truncated;
                currentImageIndex = 0;
            }

            // D-14: Neuer Lauf -- Kennung erzeugen und die vollständige Liste EINMAL
            // mitschicken, damit der Server den Fortschritt persistieren kann.
            currentRunId = generateRunId();
            pendingRunIds = imagesToOptimize.slice();
            bulkTotal = imagesToOptimize.length;
            bulkDoneCount = 0;
            bulkNotices = [];
            bulkFailedIds = [];

            // Fortschrittsanzeige initialisieren
            const ui = buildProgressUi(button);

            // Starte die Optimierung
            await optimizeNextImage(ui.progressBar, ui.progressText);

        } catch (error) {
            showBulkError(error.message || imageAiSeoAdmin.strings.bulkOptimizationError);
        } finally {
            isOptimizing = false;
            syncBulkControls();
        }
    });

    /**
     * D-14: Prüft beim Laden der Seite, ob ein Lauf unterbrochen wurde (Reload, geschlossener
     * Tab, Timeout). Der Lauf wird dabei bewusst NICHT von selbst fortgesetzt: jedes
     * fortgesetzte Bild verbraucht einen Credit, also echtes Geld. Stattdessen wird gefragt,
     * und erst eine ausdrückliche Bestätigung startet den Rest. Ist kein Lauf offen, passiert
     * nichts -- die Seite verhält sich dann exakt wie bisher.
     */
    async function checkForInterruptedBulkRun() {
        const button = $('.start-bulk-optimization');

        if (!button.length || isOptimizing) {
            return;
        }

        let response;

        try {
            response = await $.ajax({
                url: imageAiSeoAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'image_ai_seo_bulk_rename_progress',
                    nonce: imageAiSeoAdmin.nonce
                }
            });
        } catch (error) {
            // Ein fehlgeschlagener Statusabruf darf die Seite nicht blockieren -- der Nutzer
            // kann den Lauf jederzeit selbst neu starten.
            return;
        }

        if (!response || !response.success || !response.data || !response.data.remaining_ids || !response.data.remaining_ids.length) {
            return;
        }

        showResumePrompt(button, response.data);
    }

    /**
     * Baut die Rückfrage zum unterbrochenen Lauf: benennt die offene Anzahl und bietet genau
     * zwei Wege an -- fortsetzen oder verwerfen. Ohne den Verwerfen-Weg bliebe nur, das
     * Dashboard gar nicht mehr zu öffnen, um einen aufgegebenen Lauf zu stoppen.
     */
    function showResumePrompt(button, state) {
        $('.bulk-resume-prompt').remove();

        const remaining = state.remaining_ids.length;
        const total = state.total || remaining;

        const prompt = $('<div class="bulk-resume-prompt notice notice-warning"></div>');

        $('<p></p>')
            .text(imageAiSeoAdmin.strings.bulkResumePrompt
                .replace('%d', remaining)
                .replace('%total', total))
            .appendTo(prompt);

        const actions = $('<p class="bulk-resume-actions"></p>').appendTo(prompt);

        const confirmButton = $('<button type="button" class="button button-primary bulk-resume-confirm"></button>')
            .text(imageAiSeoAdmin.strings.bulkResumeConfirm)
            .appendTo(actions);

        const discardButton = $('<button type="button" class="button bulk-resume-discard"></button>')
            .text(imageAiSeoAdmin.strings.bulkResumeDiscard)
            .appendTo(actions);

        confirmButton.on('click', async function() {
            confirmButton.prop('disabled', true);
            discardButton.prop('disabled', true);
            prompt.remove();
            await startResumedBulkRun(button, state);
        });

        discardButton.on('click', async function() {
            confirmButton.prop('disabled', true);
            discardButton.prop('disabled', true);
            await discardInterruptedBulkRun(state.run_id);
            prompt.remove();
        });

        prompt.insertAfter(button);
    }

    /**
     * D-10/CRED-03: Rückfrage vor einem Lauf, dessen Auswahl die Credit-Bilanz übersteigt.
     *
     * Strukturell an showResumePrompt() angelehnt (gleiche .remove()/.insertAfter()-DOM-
     * Lebensdauer, Button-Paar). Bietet zwei Wege: "die ersten N optimieren" (schneidet die
     * Auswahl auf die bezahlbaren N IDs zurück und liefert diese Liste) oder "Abbrechen"
     * (kein Lauf, kein Credit-Verbrauch). Gibt ein Promise zurück, das auf die gekürzte
     * ID-Liste (bei Bestätigung) oder auf false (bei Abbruch) auflöst, damit der Aufrufer
     * warten kann, bevor optimizeNextImage() startet -- so beginnt nie ein Lauf ohne die
     * ausdrückliche Wahl des Nutzers.
     */
    function showBulkPreflightPrompt(button, availableCredits, selectedIds) {
        return new Promise(function(resolve) {
            $('.bulk-preflight-prompt').remove();

            const optimizable = Math.max(0, availableCredits);

            const prompt = $('<div class="bulk-preflight-prompt notice notice-warning"></div>');

            $('<p></p>')
                .text(imageAiSeoAdmin.strings.bulkPreflightPrompt
                    .replace('%total', selectedIds.length)
                    .replace('%remaining', optimizable))
                .appendTo(prompt);

            const actions = $('<p class="bulk-preflight-actions"></p>').appendTo(prompt);

            const confirmButton = $('<button type="button" class="button button-primary bulk-preflight-confirm"></button>')
                .text(imageAiSeoAdmin.strings.bulkPreflightConfirm.replace('%remaining', optimizable))
                .appendTo(actions);

            const cancelButton = $('<button type="button" class="button bulk-preflight-cancel"></button>')
                .text(imageAiSeoAdmin.strings.bulkPreflightCancel)
                .appendTo(actions);

            confirmButton.on('click', function() {
                confirmButton.prop('disabled', true);
                cancelButton.prop('disabled', true);
                prompt.remove();
                // Nur die ersten N (= bezahlbaren) IDs -- der Lauf kann so nicht mitten drin
                // an fehlenden Credits scheitern.
                resolve(selectedIds.slice(0, optimizable));
            });

            cancelButton.on('click', function() {
                confirmButton.prop('disabled', true);
                cancelButton.prop('disabled', true);
                prompt.remove();
                resolve(false);
            });

            prompt.insertAfter(button);
        });
    }

    /**
     * D-08: Holt die Credit-Bilanz frisch vom Backend, aber höchstens einmal pro 60 Sekunden.
     *
     * Der Cache (lastCreditFetchAt) verhindert, dass wiederholtes Laden/Fokussieren des
     * Dashboards das Backend mit Aufrufen überflutet. Der eigentliche Request läuft über
     * refresh_credit_balance -> /api/imageaiseo/validate und sitzt damit hinter dem Phase-6-
     * Consent-Gate (kein Request ohne Consent). Das Ergebnis wird in denselben
     * updateCreditsDisplay()-Sink gespeist, den jede Optimierung bereits benutzt.
     */
    function refreshCreditsIfStale() {
        if (!$('.remaining-credits').length) {
            return;
        }

        if (Date.now() - lastCreditFetchAt <= 60000) {
            return;
        }

        lastCreditFetchAt = Date.now();

        $.ajax({
            url: imageAiSeoAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'image_ai_seo_refresh_credit_balance',
                nonce: imageAiSeoAdmin.nonce
            }
        }).done(function(response) {
            if (response && response.success && response.data && response.data.credits !== undefined) {
                updateCreditsDisplay(response.data.credits);
            }
        });
    }

    /**
     * CRED-02/D-06: Rendert eine Upsell-CTA (Buy-Link) statt einer nackten Fehlermeldung,
     * wenn ein Request mit dem Code image_ai_seo_no_credits scheitert. Der Anker zeigt auf
     * die D-06-URL und trägt exakt dieselbe "Buy more credits"-Beschriftung wie der Button
     * in dashboard-header.php. Rein funktional -- die visuelle Gestaltung gehört zu Phase 7.1.
     */
    function renderNoCreditsUpsell(container) {
        const anchor = container && container.length ? container : $('.optimization-stats');

        $('.image-ai-seo-upsell-notice').remove();

        const notice = $('<div class="image-ai-seo-upsell-notice"></div>');
        $('<p></p>').text(imageAiSeoAdmin.strings.noCreditsUpsellMessage).appendTo(notice);
        $('<a class="button button-primary" target="_blank" rel="noopener noreferrer"></a>')
            .attr('href', 'https://imageaiseo.com/pricing')
            .text(imageAiSeoAdmin.strings.buyMoreCredits)
            .appendTo(notice);

        notice.insertAfter(anchor);

        return notice;
    }

    /**
     * Zeigt einen dauerhaften Hinweis, wenn ein Bulk-Lauf wegen fehlender oder
     * veralteter Einwilligung angehalten wurde, mit Link zum License-Tab.
     */
    function renderConsentStopNotice(container, message) {
        const anchor = container && container.length ? container : $('.optimization-stats');

        $('.image-ai-seo-consent-stop-notice').remove();

        const notice = $('<div class="image-ai-seo-upsell-notice image-ai-seo-consent-stop-notice" role="alert"></div>');
        $('<p></p>').text(message || imageAiSeoAdmin.strings.consentRequired).appendTo(notice);
        $('<a class="button button-primary"></a>')
            .attr('href', imageAiSeoAdmin.licenseUrl)
            .text(imageAiSeoAdmin.strings.goToLicenseTab)
            .appendTo(notice);

        notice.insertAfter(anchor);

        return notice;
    }

    /**
     * Setzt einen bestätigten Lauf genau dort fort, wo er unterbrochen wurde.
     */
    async function startResumedBulkRun(button, state) {
        if (isOptimizing) {
            return;
        }

        isOptimizing = true;
        button.prop('disabled', true);
        button.text(imageAiSeoAdmin.strings.optimizing);

        // Nach einem Resume ist die Liste bewusst NICHT die aktuelle Auswahl, sondern der
        // vom Server gemeldete Rest. Die vollständige ID-Liste wird nicht erneut geschickt
        // (pendingRunIds bleibt null), sonst würde der Lauf serverseitig zurückgesetzt.
        imagesToOptimize = state.remaining_ids;
        currentImageIndex = 0;
        currentRunId = state.run_id;
        pendingRunIds = null;
        bulkTotal = state.total || state.remaining_ids.length;
        bulkDoneCount = (state.processed_ids || []).length;
        bulkNotices = [];
        bulkFailedIds = (state.failed_ids || []).slice();

        // Der Hinweis steht in einer eigenen Zeile, die den ganzen Lauf über sichtbar bleibt.
        // Zuvor setzte ihn der Resume in dieselbe Zeile, die optimizeNextImage() unmittelbar
        // danach im selben synchronen Ablauf überschrieb -- er war nie lesbar.
        const ui = buildProgressUi(button, imageAiSeoAdmin.strings.bulkOptimizationResumed);

        try {
            await optimizeNextImage(ui.progressBar, ui.progressText);
        } finally {
            isOptimizing = false;
            syncBulkControls();
        }
    }

    /**
     * Verwirft den unterbrochenen Lauf serverseitig, damit der nächste Seitenaufruf nicht
     * erneut fragt.
     */
    async function discardInterruptedBulkRun(runId) {
        try {
            await $.ajax({
                url: imageAiSeoAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'image_ai_seo_discard_bulk_rename_progress',
                    nonce: imageAiSeoAdmin.nonce,
                    run_id: runId || ''
                }
            });
        } catch (error) {
            showBulkError(imageAiSeoAdmin.strings.bulkOptimizationError);
            return;
        }

        showBulkSuccess(imageAiSeoAdmin.strings.bulkResumeDiscarded);
    }

    async function optimizeNextImage(progressBar, progressText) {
        if (currentImageIndex >= imagesToOptimize.length) {
            if (progressContainer) {
                progressContainer.remove();
                progressContainer = null;
                removeBulkStatusLine();
            }
            currentRunId = null;
            pendingRunIds = null;
            showBulkSuccess(imageAiSeoAdmin.strings.bulkOptimizationComplete);
            showBulkNotices();
            showBulkFailedImages();
            return;
        }

        const imageId = imagesToOptimize[currentImageIndex];
        const progress = bulkTotal ? ((bulkDoneCount + 1) / bulkTotal) * 100 : 0;

        // Aktualisiere Fortschrittsanzeige -- gezählt wird über den gesamten Lauf hinweg,
        // damit ein fortgesetzter Lauf nicht wieder bei 1 beginnt.
        progressBar.css('width', progress + '%');
        progressText.text(imageAiSeoAdmin.strings.optimizingImage.replace('%d', bulkDoneCount + 1)
            .replace('%total', bulkTotal));

        const requestData = {
            action: 'image_ai_seo_bulk_optimize_image',
            nonce: imageAiSeoAdmin.nonce,
            id: imageId
        };

        if (currentRunId) {
            requestData.run_id = currentRunId;
        }

        // Die vollständige ID-Liste geht genau EINMAL raus, beim ersten Aufruf des Laufs.
        if (pendingRunIds) {
            requestData.image_ids = pendingRunIds;
            pendingRunIds = null;
        }

        try {
            const response = await $.ajax({
                url: imageAiSeoAdmin.ajaxurl,
                type: 'POST',
                data: requestData
            });

            if (response.success) {
                // Auch eine erfolgreiche Antwort trägt den Lauf-Zustand und damit die bereits
                // ausgemusterten IDs mit sich -- sonst ginge ein früher ausgemustertes Bild
                // verloren, sobald danach noch ein Bild erfolgreich verarbeitet wird.
                collectFailedIds(response.data && response.data.progress);

                // Aktualisiere die Statistiken
                if (response.data.stats) {
                    applyBulkStats(response.data.stats);
                }

                // Aktualisiere die Credits
                if (response.data.credits !== undefined) {
                    updateCreditsDisplay(response.data.credits);
                }

                // RENAME-03/T-04-15: nicht-fatale Hinweise (z. B. die Offload-Verweigerung
                // aus D-13) sammeln, damit sie am Ende des Laufs sichtbar werden, statt im
                // Durchlauf unterzugehen.
                if (response.data.notice) {
                    bulkNotices.push(response.data.notice);
                }

            } else {
                // D-03/D-14: Ein einzelnes Bild, das nicht verarbeitet werden konnte, beendet
                // den Lauf NICHT. Sonst genügt eine einzige beschädigte Postmeta-Zeile in der
                // Mediathek, um die gesamte Bulk-Optimierung abzubrechen -- alle nachfolgenden
                // Bilder blieben unbearbeitet. D-03 verlangt, beschädigte Bestandsdaten zu
                // erkennen und zu MELDEN; ein Abbruch ist keine Meldung. Serverseitig bleibt
                // der Vertrag aus 04-07 unverändert (wp_send_json_error pro Bild), und das
                // Bild bleibt in `remaining_ids`, sodass ein späterer Lauf es erneut versucht.
                collectFailedIds(response.data && response.data.progress);

                // CRED-02: Ein no_credits-Fehlschlag betrifft nicht nur dieses Bild -- jedes
                // weitere Bild würde am selben Gate scheitern. Statt einer nackten
                // Fehlermeldung pro Bild wird der Lauf hier beendet und eine Buy-Link-Upsell-
                // CTA gezeigt, damit der Nutzer einen Weg nach vorn hat.
                // Missing or outdated consent: nothing was sent and every further image
                // would be blocked at the same gate. Stop the run and keep a visible,
                // non-fading notice with a way to the License tab. The server left the
                // image in remaining_ids, so the run can be resumed after consent.
                if (response.data && response.data.code === 'image_ai_seo_consent_required') {
                    if (progressContainer) {
                        progressContainer.remove();
                        progressContainer = null;
                        removeBulkStatusLine();
                    }
                    currentRunId = null;
                    pendingRunIds = null;
                    renderConsentStopNotice($('.optimization-stats'), response.data.message);
                    showBulkNotices();
                    showBulkFailedImages();
                    return;
                }

                if (response.data && response.data.code === 'image_ai_seo_no_credits') {
                    if (progressContainer) {
                        progressContainer.remove();
                        progressContainer = null;
                        removeBulkStatusLine();
                    }
                    currentRunId = null;
                    pendingRunIds = null;
                    renderNoCreditsUpsell($('.optimization-stats'));
                    showBulkNotices();
                    showBulkFailedImages();
                    return;
                }

                bulkNotices.push(
                    imageAiSeoAdmin.strings.bulkImageSkipped
                        .replace('%1$d', imageId)
                        .replace('%2$s', response.data && response.data.message
                            ? response.data.message
                            : imageAiSeoAdmin.strings.bulkOptimizationError)
                );
            }

            bulkDoneCount++;
            currentImageIndex++;
            await optimizeNextImage(progressBar, progressText);
        } catch (error) {
            // Nur noch echte Transportfehler (Netzwerk, ungültiger Nonce, HTTP-Fehler)
            // landen hier -- die brechen den Lauf weiterhin ab, weil sie nicht ein einzelnes
            // Bild betreffen, sondern die Verbindung zum Server selbst.
            if (progressContainer) {
                progressContainer.remove();
                progressContainer = null;
                removeBulkStatusLine();
            }
            showBulkError(error.message || imageAiSeoAdmin.strings.optimizationError);
            showBulkNotices();
            showBulkFailedImages();
        }
    }

    /**
     * Entfernt die laufbegleitende Statuszeile, sobald der Lauf endet.
     */
    function removeBulkStatusLine() {
        if (bulkStatusLine) {
            bulkStatusLine.remove();
            bulkStatusLine = null;
        }
    }

    /**
     * Übernimmt die serverseitig ausgemusterten IDs (CR-05, MAX_ATTEMPTS) aus einer Antwort.
     * Der Server entscheidet, wann ein Bild endgültig aus dem Lauf fällt; der Client zählt
     * nichts selbst mit, sonst könnten beide Seiten auseinanderlaufen.
     */
    function collectFailedIds(progress) {
        if (!progress || !progress.failed_ids || !progress.failed_ids.length) {
            return;
        }

        progress.failed_ids.forEach(function(id) {
            const numericId = parseInt(id, 10);

            if (numericId && bulkFailedIds.indexOf(numericId) === -1) {
                bulkFailedIds.push(numericId);
            }
        });
    }

    /**
     * Benennt am Ende des Laufs die Bilder, die nach MAX_ATTEMPTS ausgemustert wurden.
     *
     * Vorher hielt genau ein solches Bild den Lauf offen: die Fortschritts-Option blieb
     * bestehen, jeder Dashboard-Aufruf versuchte es erneut, und der Lauf meldete sich nie als
     * fertig. Jetzt endet der Lauf, und der Nutzer erfährt, welche Bilder er selbst ansehen
     * muss, statt dass still weiter Credits verbraucht werden.
     */
    function showBulkFailedImages() {
        if (!bulkFailedIds.length) {
            return;
        }

        const box = $('<div class="bulk-attention-message"></div>');
        box.append($('<strong></strong>').text(imageAiSeoAdmin.strings.bulkAttentionHeadline));
        const list = $('<ul></ul>').appendTo(box);

        bulkFailedIds.forEach(function(id) {
            $('<li></li>')
                .text(imageAiSeoAdmin.strings.bulkAttentionImage.replace('%d', id))
                .appendTo(list);
        });

        box.insertAfter('.optimization-stats');
        bulkFailedIds = [];
    }

    /**
     * Zeigt die gesammelten Hinweise eines Laufs an. Zwei Arten von Hinweisen landen hier:
     * die Umbenennung wurde übersprungen, das Bild aber optimiert (D-13, Offload), und ein
     * Bild konnte gar nicht verarbeitet werden und braucht eine manuelle Prüfung (D-03,
     * beschädigte Bestandsdaten). Beide beenden den Lauf nicht.
     */
    function showBulkNotices() {
        if (!bulkNotices.length) {
            return;
        }

        const unique = bulkNotices.filter(function(notice, index) {
            return bulkNotices.indexOf(notice) === index;
        });

        const noticeBox = $('<div class="bulk-notice-message"></div>');
        noticeBox.append($('<strong></strong>').text(imageAiSeoAdmin.strings.bulkNoticesHeadline));
        const list = $('<ul></ul>').appendTo(noticeBox);

        unique.forEach(function(notice) {
            $('<li></li>').text(notice).appendTo(list);
        });

        noticeBox.insertAfter('.optimization-stats');
        bulkNotices = [];
    }

    /**
     * MEDIA-02: Treibt den Media-Library-Bulk-Lauf an, wenn die Seite mit dem
     * image_ai_seo_bulk_ids-Redirect-Argument geladen wurde (aus
     * Image_AI_SEO_Media_Columns::handle_media_bulk_action). Die ausgewählten IDs
     * werden geparst und durch DIESELBE optimizeNextImage()-Schleife geschickt, die
     * auch der Bulk-Optimization-Tab benutzt -- kein zweiter, eigener Schreibpfad.
     * Ist das Argument nicht vorhanden, passiert nichts, und die Medienübersicht
     * verhält sich exakt wie bisher.
     */
    async function launchMediaLibraryBulkRun() {
        const params = new URLSearchParams(window.location.search);
        const raw = params.get('image_ai_seo_bulk_ids');

        if (!raw) {
            return;
        }

        const ids = raw.split(',')
            .map(function(value) { return parseInt(value, 10); })
            .filter(function(value) { return value > 0; });

        if (!ids.length || isOptimizing) {
            stripBulkIdsFromUrl();
            return;
        }

        isOptimizing = true;

        // Ankerelement für die Fortschrittsanzeige auf dem Media-Library-Screen. Eine
        // minimale Statuszeile genügt hier bewusst -- die endgültige Gestaltung gehört
        // zu Phase 7.1.
        const anchor = $('<div class="image-ai-seo-media-bulk-run"></div>');
        const heading = $('.wrap h1').first();
        if (heading.length) {
            anchor.insertAfter(heading);
        } else {
            $('#wpbody-content').prepend(anchor);
        }

        imagesToOptimize = ids;
        currentImageIndex = 0;

        // D-10/CRED-03: derselbe Vorabcheck wie im Dashboard-Tab. Der Media-Library-
        // Screen rendert keine .remaining-credits, availableCredits ist dort also 0 --
        // dann wird NICHT abgeschnitten, der Lauf startet, und das serverseitige
        // Credit-Gate treibt bei fehlenden Credits die Upsell-CTA, genau wie im
        // Dashboard. Ist das Element ausnahmsweise vorhanden, greift die Kürzung 1:1.
        const availableCredits = parseInt($('.remaining-credits').data('credits') || 0, 10);
        if (availableCredits > 0 && availableCredits < imagesToOptimize.length) {
            const truncated = await showBulkPreflightPrompt(anchor, availableCredits, imagesToOptimize);
            if (truncated === false) {
                isOptimizing = false;
                anchor.remove();
                stripBulkIdsFromUrl();
                return;
            }
            imagesToOptimize = truncated;
            currentImageIndex = 0;
        }

        // Neuer Lauf: Kennung erzeugen und die vollständige Liste EINMAL mitschicken --
        // identische Semantik wie der .start-bulk-optimization-Handler.
        currentRunId = generateRunId();
        pendingRunIds = imagesToOptimize.slice();
        bulkTotal = imagesToOptimize.length;
        bulkDoneCount = 0;
        bulkNotices = [];
        bulkFailedIds = [];

        const ui = buildProgressUi(anchor);

        try {
            await optimizeNextImage(ui.progressBar, ui.progressText);

            // Sichtbarer Abschluss auf dem Media-Library-Screen (optimizeNextImage()
            // meldet sich sonst über .optimization-stats, das hier nicht existiert).
            $('.image-ai-seo-media-bulk-complete').remove();
            $('<div class="image-ai-seo-media-bulk-complete notice notice-success"></div>')
                .append($('<p></p>').text(imageAiSeoAdmin.strings.bulkOptimizationComplete))
                .appendTo(anchor);
        } catch (error) {
            showBulkError(error.message || imageAiSeoAdmin.strings.bulkOptimizationError);
        } finally {
            isOptimizing = false;
            // Argument aus der URL entfernen, damit ein Reload den Lauf nicht erneut auslöst.
            stripBulkIdsFromUrl();
        }
    }

    /**
     * Entfernt das image_ai_seo_bulk_ids-Argument aus der Adresszeile, ohne die Seite
     * neu zu laden -- ein Refresh startet den bereits gelaufenen Bulk-Lauf so nicht
     * ein zweites Mal.
     */
    function stripBulkIdsFromUrl() {
        if (!history.replaceState) {
            return;
        }

        const url = new URL(window.location.href);
        url.searchParams.delete('image_ai_seo_bulk_ids');
        history.replaceState(null, '', url.toString());
    }

    // D-14: Beim Laden der Seite prüfen, ob ein Lauf offen geblieben ist -- und danach FRAGEN,
    // nicht einfach fortsetzen.
    checkForInterruptedBulkRun();

    // MEDIA-02: Media-Library-Bulk-Aktion nach dem Redirect antreiben (No-op ohne Argument).
    launchMediaLibraryBulkRun();

    // D-08: Credit-Bilanz beim Laden des Dashboards und beim Zurückkehren auf den Tab frisch
    // holen (mit 60s-Cache), damit eine zwischenzeitliche Aufladung sichtbar wird.
    refreshCreditsIfStale();
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            refreshCreditsIfStale();
        }
    });

    function showBulkSuccess(message) {
        const successMessage = $('<div class="bulk-success-message"></div>')
            .text(message)
            .insertAfter('.optimization-stats');

        setTimeout(() => {
            successMessage.fadeOut(() => successMessage.remove());
        }, 5000);
    }

    function showBulkError(message) {
        const errorMessage = $('<div class="bulk-error-message"></div>')
            .text(message)
            .insertAfter('.optimization-stats');

        setTimeout(() => {
            errorMessage.fadeOut(() => errorMessage.remove());
        }, 5000);
    }

    // =====================
    // History Management
    // =====================

    let historyPage = 1;
    let historyLoaded = false;
    let currentFilters = {};
    let selectedHistoryIds = [];

    // History load-on-activate is handled centrally by activateTab() (F-01);
    // the former standalone `.nav-tab[href="#history"]` click loader was
    // removed to avoid a second, parallel event-handling path.

    // Filter handlers
    $('.apply-history-filters').on('click', function() {
        currentFilters = {
            search: $('#history-search').val(),
            action_type: $('#history-type-filter').val(),
            status: $('#history-status-filter').val()
        };
        historyLoaded = false;
        loadHistory(1);
    });

    $('.reset-history-filters').on('click', function() {
        $('#history-search').val('');
        $('#history-type-filter').val('');
        $('#history-status-filter').val('');
        currentFilters = {};
        historyLoaded = false;
        loadHistory(1);
    });

    // Enter key for search
    $('#history-search').on('keypress', function(e) {
        if (e.which === 13) {
            $('.apply-history-filters').trigger('click');
        }
    });

    function loadHistory(page) {
        const container = $('.history-list-container');
        const loading = container.find('.history-loading');
        const table = container.find('.history-table');
        const tbody = container.find('.history-items');
        const emptyMsg = container.find('.history-empty');
        const loadMoreBtn = container.find('.load-more-history');

        loading.show();
        if (page === 1) {
            table.hide();
            emptyMsg.hide();
            tbody.empty();
            selectedHistoryIds = [];
            updateBatchActions();
        }

        $.ajax({
            url: imageAiSeoAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'image_ai_seo_get_history',
                nonce: imageAiSeoAdmin.nonce,
                page: page,
                search: currentFilters.search || '',
                action_type: currentFilters.action_type || '',
                status: currentFilters.status || ''
            },
            success: function(response) {
                loading.hide();

                if (!response.success) {
                    emptyMsg.text(response.data.message).show();
                    return;
                }

                if (response.data.items.length === 0 && page === 1) {
                    emptyMsg.show();
                    return;
                }

                table.show();
                historyLoaded = true;
                historyPage = page;

                response.data.items.forEach(function(item) {
                    tbody.append(renderHistoryItem(item));
                });

                if (response.data.has_more) {
                    loadMoreBtn.show();
                } else {
                    loadMoreBtn.hide();
                }
            },
            error: function() {
                loading.hide();
                emptyMsg.text(imageAiSeoAdmin.strings.connectionError).show();
            }
        });
    }

    function renderHistoryItem(item) {
        const actionLabels = {
            'optimize': imageAiSeoAdmin.strings.historyOptimize || 'AI Optimization',
            'manual': imageAiSeoAdmin.strings.historyManual || 'Manual Edit',
            'revert': imageAiSeoAdmin.strings.historyRevert || 'Reverted',
            'restore': imageAiSeoAdmin.strings.historyRestore || 'Restored',
            'regenerate': imageAiSeoAdmin.strings.historyRegenerate || 'Regenerated'
        };

        const thumbnail = item.thumbnail_url
            ? '<img src="' + escapeHtml(item.thumbnail_url) + '" alt="" class="history-thumbnail">'
            : '<div class="history-thumbnail-placeholder">?</div>';

        // Checkbox for batch selection (only for non-reverted items)
        const selectLabel = imageAiSeoAdmin.strings.selectHistoryItem || 'Select this history entry';
        const checkbox = !item.is_reverted
            ? '<input type="checkbox" class="history-item-checkbox" data-history-id="' + item.id + '" aria-label="' + selectLabel + '">'
            : '<input type="checkbox" class="history-item-checkbox" aria-label="' + selectLabel + '" disabled>';

        let changes = '<div class="history-changes">';

        if (item.old_title !== item.new_title) {
            changes += '<div class="change-item"><strong>' + (imageAiSeoAdmin.strings.title || 'Title') + ':</strong> ';
            changes += '<span class="old-value">' + escapeHtml(item.old_title || '-') + '</span> → ';
            changes += '<span class="new-value">' + escapeHtml(item.new_title || '-') + '</span></div>';
        }

        if (item.old_alt_text !== item.new_alt_text) {
            changes += '<div class="change-item"><strong>' + (imageAiSeoAdmin.strings.altText || 'Alt-Text') + ':</strong> ';
            changes += '<span class="old-value">' + escapeHtml(item.old_alt_text || '-') + '</span> → ';
            changes += '<span class="new-value">' + escapeHtml(item.new_alt_text || '-') + '</span></div>';
        }

        if (item.old_filename !== item.new_filename) {
            changes += '<div class="change-item"><strong>' + (imageAiSeoAdmin.strings.filename || 'Filename') + ':</strong> ';
            changes += '<span class="old-value">' + escapeHtml(item.old_filename || '-') + '</span> → ';
            changes += '<span class="new-value">' + escapeHtml(item.new_filename || '-') + '</span></div>';
        }

        changes += '</div>';

        let actions = '<div class="history-actions">';

        if (item.is_reverted) {
            // Show restore button if reverted
            actions += '<button class="button restore-btn" data-history-id="' + item.id + '">';
            actions += (imageAiSeoAdmin.strings.restore || 'Restore') + '</button>';
        } else {
            // Show revert button if not reverted
            actions += '<button class="button revert-btn" data-history-id="' + item.id + '">';
            actions += (imageAiSeoAdmin.strings.revert || 'Revert') + '</button>';
        }

        // Always show regenerate button
        actions += '<button class="button regenerate-btn" data-attachment-id="' + item.attachment_id + '">';
        actions += (imageAiSeoAdmin.strings.regenerate || 'Regenerate') + '</button>';

        actions += '</div>';

        const statusClass = item.is_reverted ? 'status-reverted' : '';

        return '<tr class="history-item ' + statusClass + '" data-id="' + item.id + '">' +
            '<td class="column-checkbox">' + checkbox + '</td>' +
            '<td class="column-thumbnail">' + thumbnail + '</td>' +
            '<td class="column-changes">' + changes + '</td>' +
            '<td class="column-type"><span class="action-type action-' + item.action_type + '">' +
                (actionLabels[item.action_type] || item.action_type) + '</span>' +
                (item.is_reverted ? '<br><span class="reverted-badge">' + (imageAiSeoAdmin.strings.reverted || 'Reverted') + '</span>' : '') +
            '</td>' +
            '<td class="column-date">' + item.created_at_formatted + '</td>' +
            '<td class="column-actions">' + actions + '</td>' +
            '</tr>';
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Load more history
    $(document).on('click', '.load-more-history', function() {
        loadHistory(historyPage + 1);
    });

    // Revert change
    $(document).on('click', '.revert-btn', function() {
        const button = $(this);
        const historyId = button.data('history-id');
        const row = button.closest('tr');

        if (!confirm(imageAiSeoAdmin.strings.confirmRevert || 'Are you sure you want to revert this change?')) {
            return;
        }

        button.prop('disabled', true).text(imageAiSeoAdmin.strings.processing || 'Processing...');

        $.ajax({
            url: imageAiSeoAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'image_ai_seo_revert_change',
                nonce: imageAiSeoAdmin.nonce,
                history_id: historyId
            },
            success: function(response) {
                if (response.success) {
                    // Refresh the history
                    historyLoaded = false;
                    loadHistory(1);
                    showHistoryMessage(response.data.message, 'success');
                    applyBulkStats(response.data.stats);
                } else {
                    showHistoryMessage(response.data.message, 'error');
                    button.prop('disabled', false).text(imageAiSeoAdmin.strings.revert || 'Revert');
                }
            },
            error: function() {
                showHistoryMessage(imageAiSeoAdmin.strings.connectionError, 'error');
                button.prop('disabled', false).text(imageAiSeoAdmin.strings.revert || 'Revert');
            }
        });
    });

    // Restore change
    $(document).on('click', '.restore-btn', function() {
        const button = $(this);
        const historyId = button.data('history-id');

        if (!confirm(imageAiSeoAdmin.strings.confirmRestore || 'Are you sure you want to restore this change?')) {
            return;
        }

        button.prop('disabled', true).text(imageAiSeoAdmin.strings.processing || 'Processing...');

        $.ajax({
            url: imageAiSeoAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'image_ai_seo_restore_change',
                nonce: imageAiSeoAdmin.nonce,
                history_id: historyId
            },
            success: function(response) {
                if (response.success) {
                    // Refresh the history
                    historyLoaded = false;
                    loadHistory(1);
                    showHistoryMessage(response.data.message, 'success');
                    applyBulkStats(response.data.stats);
                } else {
                    showHistoryMessage(response.data.message, 'error');
                    button.prop('disabled', false).text(imageAiSeoAdmin.strings.restore || 'Restore');
                }
            },
            error: function() {
                showHistoryMessage(imageAiSeoAdmin.strings.connectionError, 'error');
                button.prop('disabled', false).text(imageAiSeoAdmin.strings.restore || 'Restore');
            }
        });
    });

    // Regenerate image
    $(document).on('click', '.regenerate-btn', function() {
        const button = $(this);
        const attachmentId = button.data('attachment-id');

        if (!confirm(imageAiSeoAdmin.strings.confirmRegenerate || 'Are you sure you want to regenerate this image? This will use 1 credit.')) {
            return;
        }

        button.prop('disabled', true).text(imageAiSeoAdmin.strings.processing || 'Processing...');

        $.ajax({
            url: imageAiSeoAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'image_ai_seo_regenerate_image',
                nonce: imageAiSeoAdmin.nonce,
                attachment_id: attachmentId
            },
            success: function(response) {
                if (response.success) {
                    // Refresh the history
                    historyLoaded = false;
                    loadHistory(1);
                    showHistoryMessage(response.data.message, 'success');
                    applyBulkStats(response.data.stats);

                    // Update credits
                    if (response.data.credits !== undefined) {
                        updateCreditsDisplay(response.data.credits);
                    }
                } else {
                    showHistoryMessage(response.data.message, 'error');
                }
                button.prop('disabled', false).text(imageAiSeoAdmin.strings.regenerate || 'Regenerate');
            },
            error: function() {
                showHistoryMessage(imageAiSeoAdmin.strings.connectionError, 'error');
                button.prop('disabled', false).text(imageAiSeoAdmin.strings.regenerate || 'Regenerate');
            }
        });
    });

    function showHistoryMessage(message, type) {
        const existingMsg = $('.history-message');
        if (existingMsg.length) {
            existingMsg.remove();
        }

        const msgClass = type === 'success' ? 'history-success-message' : 'history-error-message';
        const msgElement = $('<div class="history-message ' + msgClass + '">' + escapeHtml(message) + '</div>')
            .insertBefore('.history-list-container');

        setTimeout(function() {
            msgElement.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }

    // =====================
    // Batch Selection Handlers
    // =====================

    // Select all checkbox
    $(document).on('change', '.select-all-history', function() {
        const isChecked = $(this).is(':checked');
        $('.history-item-checkbox:not(:disabled)').prop('checked', isChecked);
        updateSelectedItems();
    });

    // Individual checkbox
    $(document).on('change', '.history-item-checkbox', function() {
        updateSelectedItems();

        // Update select all checkbox state
        const totalCheckboxes = $('.history-item-checkbox:not(:disabled)').length;
        const checkedCheckboxes = $('.history-item-checkbox:checked').length;
        $('.select-all-history').prop('checked', totalCheckboxes > 0 && totalCheckboxes === checkedCheckboxes);
    });

    function updateSelectedItems() {
        selectedHistoryIds = [];
        $('.history-item-checkbox:checked').each(function() {
            const id = $(this).data('history-id');
            if (id) {
                selectedHistoryIds.push(id);
            }
        });
        updateBatchActions();
    }

    function updateBatchActions() {
        const batchActions = $('.batch-actions');
        const selectedCount = selectedHistoryIds.length;

        if (selectedCount > 0) {
            batchActions.show();
            batchActions.find('.selected-count').text(selectedCount + ' ' + (imageAiSeoAdmin.strings.selected || 'selected'));
        } else {
            batchActions.hide();
        }
    }

    // Batch revert button
    $(document).on('click', '.batch-revert-btn', function() {
        if (selectedHistoryIds.length === 0) {
            showHistoryMessage(imageAiSeoAdmin.strings.noItemsSelected || 'No items selected.', 'error');
            return;
        }

        const confirmMsg = (imageAiSeoAdmin.strings.confirmBatchRevert || 'Are you sure you want to revert %d selected changes?')
            .replace('%d', selectedHistoryIds.length);

        if (!confirm(confirmMsg)) {
            return;
        }

        const button = $(this);
        button.prop('disabled', true).text(imageAiSeoAdmin.strings.processing || 'Processing...');

        $.ajax({
            url: imageAiSeoAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'image_ai_seo_batch_revert',
                nonce: imageAiSeoAdmin.nonce,
                history_ids: selectedHistoryIds
            },
            success: function(response) {
                if (response.success) {
                    showHistoryMessage(response.data.message, 'success');
                    applyBulkStats(response.data.stats);
                    historyLoaded = false;
                    loadHistory(1);
                } else {
                    showHistoryMessage(response.data.message, 'error');
                    applyBulkStats(response.data && response.data.stats);
                }
            },
            error: function() {
                showHistoryMessage(imageAiSeoAdmin.strings.connectionError || 'Connection error.', 'error');
            },
            complete: function() {
                button.prop('disabled', false).text(imageAiSeoAdmin.strings.revert || 'Revert Selected');
            }
        });
    });

    // Clear selection button
    $(document).on('click', '.batch-clear-selection', function() {
        $('.history-item-checkbox').prop('checked', false);
        $('.select-all-history').prop('checked', false);
        selectedHistoryIds = [];
        updateBatchActions();
    });

    // =====================
    // Credit Warning System
    // =====================

    /**
     * Shows exactly the header badge that matches the balance and hides the other.
     *
     * Both badges are rendered by dashboard-header.php, so the server-side state of
     * the page load is replaced here, not added to. The rule mirrors
     * Image_AI_SEO_Credit_Handler::get_warning_level(): 0 or less is "No credits
     * remaining!", below the threshold is "Low credits!", otherwise no badge. A
     * balance reaching this function always comes from a request with an API key.
     */
    function checkCreditWarning(credits) {
        const threshold = parseInt(imageAiSeoAdmin.credits && imageAiSeoAdmin.credits.lowThreshold, 10) || 10;
        let level = '';

        if (credits <= 0) {
            level = 'empty';
        } else if (credits < threshold) {
            level = 'low';
        }

        $('.credit-warning[data-credit-warning]').each(function() {
            $(this).prop('hidden', $(this).attr('data-credit-warning') !== level);
        });
    }

    /**
     * Single sink for every new balance: count, data attribute, badge and the bulk
     * credit hint are updated together, so they cannot disagree.
     */
    function updateCreditsDisplay(credits) {
        const balance = parseInt(credits, 10);

        if (isNaN(balance)) {
            return;
        }

        const creditsElement = $('.remaining-credits');
        creditsElement.attr('data-credits', balance).data('credits', balance);
        creditsElement.text((imageAiSeoAdmin.strings.remainingCredits || 'Remaining credits') + ': ' + balance);
        checkCreditWarning(balance);
        syncBulkControls();
    }

    /**
     * Übernimmt frische Zähler (total/optimized/unoptimized) in die Bulk-Statistik und
     * gleicht Credit-Hinweis und Start-Button daran an. Quelle sind die Antworten der
     * Bulk-Optimierung sowie von Revert/Restore/Regenerate in der Historie.
     */
    function applyBulkStats(stats) {
        if (!stats) {
            return;
        }

        $('.optimization-stats .stat-value').first().text(stats.total);
        $('.stat-value.optimized').text(stats.optimized);
        $('.stat-value.unoptimized').text(stats.unoptimized);
        syncBulkControls();
    }

    /**
     * Hält den Credit-Hinweis ("Optimizing N images will use N credits ...") und den
     * Start-Button aktuell: Ohne offene Bilder wird der Button deaktiviert und als
     * "All images optimized" beschriftet, sonst zeigt der Hinweis die aktuelle Anzahl und
     * das aktuelle Guthaben.
     *
     * The hint is updated during a run as well, from the same sink as the header badge
     * (updateCreditsDisplay()/applyBulkStats()), so both always show the same balance.
     * Only the button is left alone while a run is active: it reads "Optimizing..." and
     * stays disabled until the run ends.
     */
    function syncBulkControls() {
        const button = $('.start-bulk-optimization');
        const unoptimizedElement = $('.stat-value.unoptimized');

        if (!button.length || !unoptimizedElement.length) {
            return;
        }

        const unoptimized = parseInt(unoptimizedElement.text(), 10) || 0;
        const credits = parseInt($('.remaining-credits').data('credits') || 0, 10);
        const strings = imageAiSeoAdmin.strings;
        let info = $('.bulk-credit-info');

        if (unoptimized <= 0) {
            info.remove();
            if (isOptimizing) {
                return;
            }
            button.prop('disabled', true)
                .addClass('bulk-all-done')
                .text(strings.allImagesOptimized);
            return;
        }

        if (!info.length) {
            info = $('<div class="bulk-credit-info"></div>').insertBefore(button);
        }

        info.empty().append(document.createTextNode(strings.bulkCreditInfo
            .replace('%1$d', unoptimized)
            .replace('%2$d', unoptimized)
            .replace('%3$d', credits)));

        if (credits < unoptimized) {
            info.append('<br>');
            $('<span class="credit-insufficient"></span>').text(strings.bulkCreditInsufficient).appendTo(info);
        }

        if (isOptimizing) {
            return;
        }

        button.prop('disabled', false)
            .removeClass('bulk-all-done')
            .text(strings.startBulkOptimization);
    }

    // =====================
    // Better Error Messages
    // =====================

    function getErrorMessage(error, defaultMsg) {
        if (!error) return defaultMsg || imageAiSeoAdmin.strings.errorGeneric;

        const errorMsg = error.message || error;
        const lowerError = errorMsg.toLowerCase();

        // Map common errors to user-friendly messages
        if (lowerError.includes('insufficient') || lowerError.includes('credit')) {
            return imageAiSeoAdmin.strings.errorInsufficientCredits || errorMsg;
        }
        if (lowerError.includes('invalid') && lowerError.includes('api')) {
            return imageAiSeoAdmin.strings.errorInvalidApiKey || errorMsg;
        }
        if (lowerError.includes('timeout') || lowerError.includes('timed out')) {
            return imageAiSeoAdmin.strings.errorNetworkTimeout || errorMsg;
        }
        if (lowerError.includes('not found') || lowerError.includes('does not exist')) {
            return imageAiSeoAdmin.strings.errorImageNotFound || errorMsg;
        }
        if (lowerError.includes('already exists') || lowerError.includes('duplicate')) {
            return imageAiSeoAdmin.strings.errorFilenameExists || errorMsg;
        }
        if (lowerError.includes('invalid') && (lowerError.includes('image') || lowerError.includes('file'))) {
            return imageAiSeoAdmin.strings.errorInvalidImage || errorMsg;
        }

        return errorMsg || defaultMsg || imageAiSeoAdmin.strings.errorGeneric;
    }

    // =====================
    // Statistics Dashboard
    // =====================

    let statisticsLoaded = false;

    // Statistics load-on-activate is handled centrally by activateTab() (F-01);
    // the former standalone `.nav-tab[href="#statistics"]` click loader was
    // removed to avoid a second, parallel event-handling path.

    // Refresh button
    $(document).on('click', '.refresh-statistics', function() {
        const button = $(this);
        button.prop('disabled', true);
        button.find('.dashicons').addClass('spin');
        loadStatistics(function() {
            button.prop('disabled', false);
            button.find('.dashicons').removeClass('spin');
        });
    });

    function loadStatistics(callback) {
        const loading = $('.statistics-loading');
        const content = $('.statistics-content');

        loading.show();
        content.hide();

        $.ajax({
            url: imageAiSeoAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'image_ai_seo_get_statistics',
                nonce: imageAiSeoAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateStatisticsUI(response.data);
                    statisticsLoaded = true;
                }
            },
            error: function() {
                console.error('Failed to load statistics');
            },
            complete: function() {
                loading.hide();
                content.show();
                if (typeof callback === 'function') {
                    callback();
                }
            }
        });
    }

    function updateStatisticsUI(data) {
        // Image statistics
        if (data.images) {
            $('#stat-total-images').text(formatNumber(data.images.total));
            $('#stat-optimized-images').text(formatNumber(data.images.optimized));
            $('#stat-unoptimized-images').text(formatNumber(data.images.unoptimized));
            $('#stat-optimization-rate').text(data.images.optimization_rate + '%');

            // Progress bar
            const progressPercent = data.images.optimization_rate;
            $('#optimization-progress-bar').css('width', progressPercent + '%');
            $('#progress-optimized-label').text(formatNumber(data.images.optimized) + ' optimized');
            $('#progress-total-label').text(formatNumber(data.images.total) + ' total');
        }

        // Credit statistics
        if (data.credits) {
            $('#stat-credits-remaining').text(formatNumber(data.credits.remaining));
            $('#stat-credits-today').text(formatNumber(data.credits.used_today));
            $('#stat-credits-month').text(formatNumber(data.credits.used_this_month));
            $('#stat-credits-total').text(formatNumber(data.credits.used_total));
        }

        // History statistics
        if (data.history) {
            $('#stat-action-optimize').text(formatNumber(data.history.optimizations));
            $('#stat-action-manual').text(formatNumber(data.history.manual_edits));
            $('#stat-action-regenerate').text(formatNumber(data.history.regenerations));
            $('#stat-action-revert').text(formatNumber(data.history.reverts));
            $('#stat-action-restore').text(formatNumber(data.history.restores));
        }

        // Activity chart
        if (data.recent_activity) {
            renderActivityChart(data.recent_activity);
        }
    }

    function renderActivityChart(activityData) {
        const chartContainer = $('#activity-chart');
        chartContainer.empty();

        // Handle empty or missing data
        if (!activityData || activityData.length === 0) {
            chartContainer.html('<p class="no-activity">' +
                (imageAiSeoAdmin.strings.noRecentActivity || 'No recent activity') + '</p>');
            return;
        }

        // A-03: visually-hidden accessible data table built from the SAME
        // activityData array (no second AJAX call). Prepended so assistive tech
        // reads the tabular alternative to the visual bars. .screen-reader-text
        // is the WP-core hidden-text class.
        const strings = imageAiSeoAdmin.strings || {};
        let tableRows = '';
        activityData.forEach(function(day) {
            tableRows += '<tr><td>' + escapeHtml(day.label) + '</td><td>' + day.count + '</td></tr>';
        });
        const accessibleTable = '<table class="screen-reader-text">' +
            '<caption>' + (strings.activityDataHeading || 'Activity data, last 7 days') + '</caption>' +
            '<thead><tr>' +
            '<th scope="col">' + (strings.activityDataDay || 'Day') + '</th>' +
            '<th scope="col">' + (strings.activityDataCount || 'Optimizations') + '</th>' +
            '</tr></thead>' +
            '<tbody>' + tableRows + '</tbody></table>';
        chartContainer.append(accessibleTable);

        // Find max value for scaling
        const maxCount = Math.max(...activityData.map(d => d.count), 1);

        activityData.forEach(function(day) {
            let bar;
            if (day.count === 0) {
                // G-03: zero-value bars get a distinct flat floor-tick modifier
                // and no floating "0" value; the count stays available in the
                // A-03 accessible table above. No inline height style so the CSS
                // .chart-bar-zero fixed-height rule applies cleanly.
                bar = $('<div class="chart-bar-wrapper">' +
                    '<div class="chart-bar chart-bar-zero"></div>' +
                    '<span class="chart-bar-label">' + escapeHtml(day.label) + '</span>' +
                    '</div>');
            } else {
                const heightPercent = (day.count / maxCount) * 100;
                bar = $('<div class="chart-bar-wrapper">' +
                    '<div class="chart-bar" style="height: ' + heightPercent + '%;"></div>' +
                    '<span class="chart-bar-value">' + day.count + '</span>' +
                    '<span class="chart-bar-label">' + escapeHtml(day.label) + '</span>' +
                    '</div>');
            }
            chartContainer.append(bar);
        });
    }

    function formatNumber(num) {
        if (num === undefined || num === null) return '0';
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
}); 