jQuery(document).ready(function($) {
    $('.image-ai-seo-edit .save-metadata').on('click', function(e) {
        e.preventDefault();
        
        const container = $(this).closest('.image-ai-seo-edit');
        const id = container.data('id');
        const title = container.find('.title-input').val();
        const alt = container.find('.alt-input').val();
        const filename = container.find('.filename-input').val();
        const description = container.find('.description-input').val() || '';
        const extension = container.find('.file-extension').text();

        // Entferne die Dateiendung, falls sie im Dateinamen enthalten ist
        const newFilename = filename.replace(extension, '');
        
        // Entferne alte Nachrichten
        container.find('.success-message, .error-message').remove();
        
        // Setze Loading-Status
        container.addClass('saving');
        
        const data = {
            action: 'image_ai_seo_update_image_metadata',
            nonce: imageAiSeoAdmin.nonce,
            id: id,
            title: title,
            alt: alt,
            filename: newFilename,
            description: description
        };
        
        $.post(imageAiSeoAdmin.ajaxurl, data, function(response) {
            container.removeClass('saving');
            
            if (response.success) {
                // Aktualisiere den Dateinamen im Input-Feld
                container.find('.filename-input').val(response.data.new_filename);
                
                // Aktualisiere die Gesamtstatistik, falls verfügbar
                if (response.data && response.data.total_optimized) {
                    $('.total-optimized-count').text(response.data.total_optimized);
                }
                
                $('<div class="success-message">' + imageAiSeoAdmin.strings.success + '</div>')
                    .appendTo(container);
            } else {
                $('<div class="error-message">' + (response.data ? response.data.message : imageAiSeoAdmin.strings.error) + '</div>')
                    .appendTo(container);
            }
            
            // Entferne die Erfolgsmeldung nach 3 Sekunden
            setTimeout(function() {
                container.find('.success-message').fadeOut(function() {
                    $(this).remove();
                });
            }, 3000);
        }).fail(function() {
            container.removeClass('saving');
            $('<div class="error-message">' + imageAiSeoAdmin.strings.error + '</div>')
                .appendTo(container);
        });
    });

    // Optimize Button Handler
    $('.image-ai-seo-edit .optimize-image').on('click', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const container = button.closest('.image-ai-seo-edit');
        const id = container.data('id');
        
        // Entferne alte Nachrichten
        container.find('.success-message, .error-message').remove();
        
        // Setze Loading-Status
        button.prop('disabled', true);
        container.addClass('saving');
        
        const data = {
            action: 'image_ai_seo_optimize_image',
            nonce: imageAiSeoAdmin.nonce,
            id: id
        };
        
        $.post(imageAiSeoAdmin.ajaxurl, data, function(response) {
            container.removeClass('saving');
            
            if (response.success) {
                // Aktualisiere die Eingabefelder mit den optimierten Werten
                container.find('.title-input').val(response.data.title);
                container.find('.alt-input').val(response.data.alt_text);
                container.find('.filename-input').val(response.data.filename);

                // Aktualisiere auch die Description, falls vorhanden
                if (response.data.description) {
                    container.find('.description-input').val(response.data.description);
                }

                // Aktualisiere die Credits-Anzeige
                $('.remaining-credits').text(
                    imageAiSeoAdmin.strings.remainingCredits + ' ' + response.data.credits
                );
                
                $('<div class="success-message">' + response.data.message + '</div>')
                    .appendTo(container);
            } else if (response.data && response.data.code === 'image_ai_seo_no_credits') {
                // CRED-02 (BLOCKER 2, surface 2/2): the quick-edit optimize action at
                // 0 credits shows the buy-link upsell CTA instead of a bare error div.
                renderNoCreditsUpsell(container);
            } else {
                $('<div class="error-message">' + (response.data ? response.data.message : imageAiSeoAdmin.strings.optimizationError) + '</div>')
                    .appendTo(container);
            }
            
            // Entferne die Erfolgsmeldung nach 3 Sekunden
            setTimeout(function() {
                container.find('.success-message, .error-message').fadeOut(function() {
                    $(this).remove();
                });
            }, 3000);
        }).fail(function() {
            container.removeClass('saving');
            $('<div class="error-message">' + imageAiSeoAdmin.strings.connectionError + '</div>')
                .appendTo(container);
        }).always(function() {
            button.prop('disabled', false);
        });
    });

    // =====================
    // Attachment Field Button (Grid-Modal & Edit Page)
    // =====================

    // Handler für den Optimize-Button im Attachment-Feld
    $(document).on('click', '.optimize-attachment-btn', function(e) {
        e.preventDefault();

        const button = $(this);
        const container = button.closest('.image-ai-seo-attachment-field');
        const id = button.data('id');
        const spinner = container.find('.spinner');
        const status = container.find('.optimization-status');

        // Verhindere doppelte Klicks
        if (button.prop('disabled')) {
            return;
        }

        button.prop('disabled', true);
        spinner.addClass('is-active');
        status.text(imageAiSeoAdmin.strings.optimizing || 'Optimizing...');

        $.ajax({
            url: imageAiSeoAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'image_ai_seo_optimize_single_image',
                nonce: imageAiSeoAdmin.nonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    // PREV-01: optimize_single_image is now preview-only -- nothing has
                    // been written yet. Open the preview dialog with the suggestion
                    // instead of calling updateAttachmentFields() here. The credits
                    // display is only refreshed once the user confirms an apply.
                    status.text('').removeClass('error success');
                    container.find('.image-ai-seo-upsell-notice').remove();
                    // Pass the opener element explicitly: by the time this success
                    // callback runs the button has been disabled (line above), so it
                    // is no longer document.activeElement — capturing it here keeps
                    // focus-restore-on-close pointed at the real opener (07.1 a11y).
                    openPreviewDialog(id, response.data, container, button.get(0));
                } else if (response.data && response.data.code === 'image_ai_seo_no_credits') {
                    // CRED-02 (BLOCKER 2, surface 1/2): nothing to preview at 0 credits.
                    // Render the buy-link upsell CTA inline instead of the generic error.
                    status.text('').removeClass('error');
                    renderNoCreditsUpsell(container);
                } else {
                    status.text(response.data.message || imageAiSeoAdmin.strings.optimizationError).addClass('error');

                    setTimeout(function() {
                        status.text('').removeClass('error');
                    }, 5000);
                }
            },
            error: function() {
                status.text(imageAiSeoAdmin.strings.connectionError || 'Connection error').addClass('error');

                setTimeout(function() {
                    status.text('').removeClass('error');
                }, 5000);
            },
            complete: function() {
                button.prop('disabled', false);
                spinner.removeClass('is-active');
            }
        });
    });

    /**
     * Aktualisiert die Attachment-Felder im Modal/Formular
     */
    function updateAttachmentFields(attachmentId, data) {
        // Für das Media Modal (Grid-Ansicht)
        if (wp && wp.media && wp.media.frame) {
            const selection = wp.media.frame.state().get('selection');
            if (selection) {
                const attachment = selection.get(attachmentId);
                if (attachment) {
                    attachment.set({
                        title: data.title,
                        alt: data.alt_text,
                        description: data.description || ''
                    });
                }
            }
        }

        // Für die Standard-Attachment-Bearbeitungsseite
        const form = $('form#post');
        if (form.length) {
            form.find('input[name="post_title"]').val(data.title);
            form.find('textarea[name="post_content"]').val(data.description || '');
            form.find('input[name="_wp_attachment_image_alt"]').val(data.alt_text);
        }

        // Für das Compat-Feld im Modal
        const compatFields = $('.compat-field-_wp_attachment_image_alt input');
        if (compatFields.length) {
            compatFields.val(data.alt_text);
        }

        // Für Title-Feld im Modal
        const titleField = $('[data-setting="title"]');
        if (titleField.length) {
            titleField.val(data.title).trigger('change');
        }

        // Für Alt-Text-Feld im Modal
        const altField = $('[data-setting="alt"]');
        if (altField.length) {
            altField.val(data.alt_text).trigger('change');
        }

        // Für Description-Feld im Modal
        const descField = $('[data-setting="description"]');
        if (descField.length) {
            descField.val(data.description || '').trigger('change');
        }
    }

    // =====================
    // Preview Dialog (PREV-01/PREV-02/D-01/D-02/D-04)
    // =====================

    /**
     * Aktualisiert die Credits-Anzeige an allen bekannten Stellen.
     */
    // Remembers the control that had focus before the preview dialog opened so
    // focus can be restored to it on close (keyboard-a11y, 07.1 UI-SPEC).
    let previewDialogPreviousFocus = null;

    function updateCreditsDisplay(credits) {
        if (credits === undefined || credits === null) {
            return;
        }
        $('.remaining-credits').text(
            (imageAiSeoAdmin.strings.remainingCredits || 'Remaining credits') + ' ' + credits
        );
    }

    /**
     * Rendert die CRED-02/D-06-Kauf-CTA (Buy-Link) inline in den übergebenen
     * Container, statt einer generischen Fehlermeldung. Eigenständige
     * Implementierung -- admin-dashboard.js hat seine eigene Variante, es gibt
     * kein gemeinsames Modulsystem zwischen den beiden Dateien.
     */
    function renderNoCreditsUpsell(container) {
        const $container = $(container);
        $container.find('.image-ai-seo-upsell-notice').remove();

        const notice = $('<div class="image-ai-seo-upsell-notice"></div>');
        $('<p></p>')
            .text(imageAiSeoAdmin.strings.noCreditsUpsellMessage || '')
            .appendTo(notice);
        // D-06: der Buy-Link zeigt auf die Preisseite von imageaiseo.com und
        // übernimmt das exakte Label aus dashboard-header.php.
        $('<a class="button button-primary" target="_blank" rel="noopener"></a>')
            .attr('href', 'https://imageaiseo.com/pricing')
            .text(imageAiSeoAdmin.strings.buyMoreCredits || 'Buy more credits')
            .appendTo(notice);

        $container.append(notice);
    }

    /**
     * Befüllt die sichtbaren Felder des Preview-Dialogs mit einer
     * Preview-Antwort (aus optimize_single_image, generate()-Seite). Wird sowohl
     * beim Öffnen als auch beim In-Place-Regenerate verwendet.
     */
    function populatePreviewDialog(dialog, data) {
        dialog.find('.preview-title-input').val(data.title || '');
        dialog.find('.preview-alt-input').val(data.alt_text || '');
        dialog.find('.preview-description-input').val(data.description || '');
        dialog.find('.preview-filename-input').val(data.filename || '');
        dialog.find('.preview-current-filename').text(data.current_filename || '');
        dialog.find('.preview-suggested-filename').text(data.filename || '');
        dialog.find('.preview-image-filename').text(data.current_filename || '');

        const thumb = dialog.find('.preview-image-thumb');
        if (data.thumbnail_url) {
            thumb.attr('src', data.thumbnail_url).prop('hidden', false);
        } else {
            thumb.attr('src', '').prop('hidden', true);
        }
    }

    /**
     * Schließt den Preview-Dialog und räumt seine Inline-Meldungen auf.
     */
    function closePreviewDialog() {
        const dialog = $('#image-ai-seo-preview-dialog');
        dialog.prop('hidden', true);
        dialog.find('.preview-dialog-messages').empty();

        // Restore focus to whatever opened the dialog. .focus() on a detached
        // node is a documented no-op, so this degrades gracefully.
        if (previewDialogPreviousFocus && typeof previewDialogPreviousFocus.focus === 'function') {
            previewDialogPreviousFocus.focus();
        }
        previewDialogPreviousFocus = null;
    }

    /**
     * Rendert einen Fehler aus einer Dialog-AJAX-Antwort in den Meldungsbereich
     * des Dialogs. Bei image_ai_seo_no_credits wird die Kauf-CTA angezeigt.
     */
    function renderPreviewDialogError(dialog, response) {
        const messages = dialog.find('.preview-dialog-messages').empty();

        if (response && response.data && response.data.code === 'image_ai_seo_no_credits') {
            renderNoCreditsUpsell(messages);
            return;
        }

        const message = (response && response.data && response.data.message)
            ? response.data.message
            : imageAiSeoAdmin.strings.optimizationError;
        $('<div class="error-message"></div>').text(message).appendTo(messages);
    }

    /**
     * Öffnet den Preview-Dialog mit einer preview-only optimize_single_image
     * Antwort und verdrahtet seine Confirm/Discard/Regenerate-Aktionen.
     *
     * Confirm -> apply_optimize_preview mit den angehakten fields[] (erster und
     * einziger Schreibpfad). Discard -> nur schließen, kein Request. Regenerate ->
     * optimize_single_image mit regenerate:1 (preview-only Force-Pfad aus Plan
     * 07-03), aktualisiert den Vorschlag in place, ohne zu schreiben/umzubenennen.
     */
    function openPreviewDialog(id, data, sourceContainer, opener) {
        const dialog = $('#image-ai-seo-preview-dialog');
        if (!dialog.length) {
            return;
        }

        populatePreviewDialog(dialog, data);
        dialog.find('.preview-field-toggle').prop('checked', true);
        dialog.find('.preview-dialog-messages').empty();

        // Capture the pre-open focus target BEFORE the dialog becomes visible,
        // then move focus to its first real field once shown (keyboard-a11y).
        // Prefer the explicit opener passed by the caller: the triggering button
        // may already be disabled (and thus blurred) by the time an async caller
        // opens the dialog, so document.activeElement is not a reliable opener.
        previewDialogPreviousFocus = opener || document.activeElement;
        dialog.prop('hidden', false);
        dialog.find('.preview-title-input').first().focus();

        updateCreditsDisplay(data.credits);

        // Confirm: schreibe nur die angehakten Felder über apply_optimize_preview.
        dialog.find('.preview-confirm-btn').off('click.imageAiSeoPreview').on('click.imageAiSeoPreview', function() {
            const confirmBtn = $(this);
            const fields = [];
            dialog.find('.preview-field-toggle:checked').each(function() {
                fields.push($(this).val());
            });

            confirmBtn.prop('disabled', true);
            dialog.find('.preview-dialog-messages').empty();

            $.ajax({
                url: imageAiSeoAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'image_ai_seo_apply_optimize_preview',
                    nonce: imageAiSeoAdmin.nonce,
                    id: id,
                    fields: fields
                },
                success: function(response) {
                    if (response.success) {
                        // Erst NACH bestätigtem Apply die Felder im Modal/Formular setzen.
                        updateAttachmentFields(id, response.data);
                        updateCreditsDisplay(response.data.credits);
                        if (sourceContainer) {
                            $(sourceContainer).find('.optimization-status')
                                .text(imageAiSeoAdmin.strings.optimized || 'Optimized!');
                        }
                        closePreviewDialog();
                    } else {
                        renderPreviewDialogError(dialog, response);
                    }
                },
                error: function() {
                    renderPreviewDialogError(dialog, null);
                },
                complete: function() {
                    confirmBtn.prop('disabled', false);
                }
            });
        });

        // Discard: kein Request. Der Server-Cache (D-03) bietet denselben Vorschlag
        // beim erneuten Öffnen ohne neue Abbuchung wieder an (PREV-02).
        dialog.find('.preview-discard-btn').off('click.imageAiSeoPreview').on('click.imageAiSeoPreview', function() {
            closePreviewDialog();
        });

        // Regenerate (D-02): preview-only Force-Pfad via optimize_single_image mit
        // regenerate:1. Bewusst NICHT der History-Apply-Pfad, der vor der
        // Bestätigung schreibt/umbenennt.
        dialog.find('.preview-regenerate-btn').off('click.imageAiSeoPreview').on('click.imageAiSeoPreview', function() {
            if (!window.confirm(imageAiSeoAdmin.strings.confirmRegenerate)) {
                return;
            }

            const regenerateBtn = $(this);
            regenerateBtn.prop('disabled', true);
            dialog.find('.preview-dialog-messages').empty();

            $.ajax({
                url: imageAiSeoAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'image_ai_seo_optimize_single_image',
                    nonce: imageAiSeoAdmin.nonce,
                    id: id,
                    regenerate: 1
                },
                success: function(response) {
                    if (response.success) {
                        // In place aktualisieren -- Dialog bleibt offen, nichts geschrieben.
                        populatePreviewDialog(dialog, response.data);
                        updateCreditsDisplay(response.data.credits);
                    } else {
                        renderPreviewDialogError(dialog, response);
                    }
                },
                error: function() {
                    renderPreviewDialogError(dialog, null);
                },
                complete: function() {
                    regenerateBtn.prop('disabled', false);
                }
            });
        });
    }

    // Escape closes the preview dialog exactly like Discard. Delegated and bound
    // once (not per-open) so handlers never accumulate across repeated opens;
    // reuses closePreviewDialog(), the same function .preview-discard-btn calls.
    $(document).on('keydown', '#image-ai-seo-preview-dialog', function(e) {
        if (e.key === 'Escape' && !$(this).prop('hidden')) {
            closePreviewDialog();
        }
    });
});