jQuery(function($){
  function parseJson(id){ try { return JSON.parse($(id).val() || '{}'); } catch(e){ return {}; } }
  function updateSelected(){ $('.alma-selected-counter').text($('.alma-select-item:checked').length+' selezionati (max 500)'); }
  function toggleSearchHints(){
    var m = $('#import_search_model').val();
    $('.alma-search-term-wrap').toggle(m === 'freetext_search');
    $('.alma-destination-wrap').toggle(m !== 'freetext_search');
  }
  function syncResultFilters(){
    var hideExisting = $('input[name="hide_existing"]').is(':checked');
    var showExisting = $('input[name="show_existing"]').is(':checked');
    $('.alma-row-existing').toggle(!hideExisting || showExisting);
  }
  function renderProviderFields(){
    var existingSettings = parseJson('#alma-existing-settings');
    var existingCredFlags = parseJson('#alma-existing-credentials-flags');
    var preset = $('#provider_preset').val();
    var $settings = $('#alma-guided-settings');
    var $creds = $('#alma-guided-credentials');
    var isViator = preset === 'viator';
    var isGetYourGuide = preset === 'getyourguide';
    var isGygCsv = preset === 'gyg_csv';
    $('#alma-advanced-credentials').toggle(!isViator && !isGetYourGuide && !isGygCsv);
    $('#alma-viator-credentials-note').toggle(isViator);
    $settings.html(''); $creds.html('');
    if(isViator){
      $settings.append('<p><label>Modalità <select name="settings_fields[mode]"><option value="create_update">create_update</option><option value="create_only">create_only</option></select></label></p>');
      $creds.append('<p><label><strong>Viator API key *</strong><br/><input type="password" name="credentials_fields[api_key]" class="regular-text" '+(existingCredFlags.api_key ? '' : 'required')+' placeholder="'+(existingCredFlags.api_key ? 'già salvata' : '')+'" autocomplete="off"></label></p>');
    }
    if(isGygCsv){
      $settings.append('<p class="description">Import CSV locale: nessuna chiamata esterna. Il dominio originale (.com/.it) viene preservato.</p>');
      $settings.append('<p><label><strong>Partner ID *</strong><br/><input type="text" name="settings_fields[partner_id]" class="regular-text" required></label></p>');
      $settings.append('<p><label><strong>UTM medium</strong><br/><input type="text" name="settings_fields[utm_medium]" value="online_publisher" class="regular-text"></label></p>');
    }
    if(isGetYourGuide){
      $settings.append('<p class="description">Richiede accesso GetYourGuide Partner API e token X-ACCESS-TOKEN. Il livello API disponibile può influire sui campi restituiti.</p>');
      $settings.append('<p><label><strong>Lingua contenuti</strong><br/><input type="text" name="settings_fields[cnt_language]" value="it" class="regular-text"></label></p>');
      $settings.append('<p><label><strong>Valuta</strong><br/><input type="text" name="settings_fields[currency]" value="EUR" class="regular-text"></label></p>');
      $settings.append('<p><label><strong>Query predefinita</strong><br/><input type="text" name="settings_fields[default_query]" class="regular-text" placeholder="es. Lecce"></label></p>');
      $settings.append('<p><label><strong>Limite risultati</strong><br/><input type="number" name="settings_fields[limit]" value="20" min="1" max="100"></label></p>');
      $settings.append('<p><label><strong>Timeout API</strong><br/><input type="number" name="settings_fields[timeout]" value="10" min="3" max="30"></label></p>');
      $creds.append('<p><label><strong>Access token GetYourGuide *</strong><br/><input type="password" name="credentials_fields[access_token]" class="regular-text" '+(existingCredFlags.access_token ? '' : 'required')+' placeholder="'+(existingCredFlags.access_token ? 'già salvato' : '')+'" autocomplete="off"></label></p><p class="description">Token configurato/non configurato: il valore salvato non viene mostrato in chiaro.</p>');
    }
    if(existingSettings.mode){ $settings.find('select[name="settings_fields[mode]"]').val(existingSettings.mode); }
    ['cnt_language','currency','default_query','limit','timeout','partner_id','utm_medium'].forEach(function(k){ if(existingSettings[k] !== undefined){ $settings.find('[name="settings_fields['+k+']"]').val(existingSettings[k]); } });
  }

  $(document).on('click','.alma-toggle-source-form',function(){
    $('#alma-source-form-wrap').slideToggle(150, function(){ if($(this).is(':visible')) $('#name').trigger('focus'); });
  });
  $(document).on('change','#provider_preset', renderProviderFields);
  $(document).on('submit','#alma-source-form',function(){
    if(!$('#name').val().trim() || !$('#provider_label').val().trim()){ alert('Name e Provider sono obbligatori.'); return false; }
  });
  $(document).on('click','.alma-test-connection',function(e){
    e.preventDefault();
    var $btn=$(this), $row=$btn.closest('td, .alma-test-connection-wrap'), $res=$row.find('.alma-inline-result').first();
    $res.removeClass('ok err').text('Test in corso...');
    $.post(ajaxurl,{action:'alma_test_source_connection', nonce:(window.almaSourcePresets&&almaSourcePresets.nonce)||(window.almaSources&&almaSources.testNonce)||'', source_id:$btn.data('source-id')})
      .done(function(r){ $res.addClass(r&&r.success?'ok':'err').text((r&&r.data&&r.data.message)||'Risposta non valida'); })
      .fail(function(){ $res.addClass('err').text('Errore di rete'); });
  });

  $(document).on('click','.alma-select-all',function(){ $('.alma-select-item:visible:not(:disabled)').slice(0,500).prop('checked',true); updateSelected();});
  $(document).on('click','.alma-deselect-all',function(){ $('.alma-select-item').prop('checked',false); updateSelected();});
  $(document).on('change','.alma-select-item',function(){ var checked=$('.alma-select-item:checked'); if(checked.length>500){ $(this).prop('checked',false); alert('Puoi selezionare massimo 500 record per batch.'); } updateSelected(); });
  $(document).on('change','#import_search_model',toggleSearchHints);
  $(document).on('change','input[name="import_availability_range"]',function(){ $('.alma-date-custom-wrap').toggle($(this).val()==='custom'); });
  $(document).on('change','input[name="hide_existing"], input[name="show_existing"]',syncResultFilters);
  $(document).on('change','input[name="auto_fill_new_items"]',function(){ $('.alma-auto-fill-note').toggle($(this).is(':checked')); });
  $(document).on('click','.alma-toggle-advanced-filters',function(e){ e.preventDefault(); $('.alma-advanced-filters').toggleClass('is-open'); });



  var GYG_JS_VERSION = (window.almaSourcePresets && almaSourcePresets.gygJsVersion) || '2.28.1';
  var gygState = null;
  if(window.console && console.info){ console.info('GYG CSV modal JS loaded: '+GYG_JS_VERSION); }
  function gygNonce(){ return (window.almaSourcePresets && almaSourcePresets.gygNonce) || ''; }
  function gygAjaxUrl(){ return (window.almaSourcePresets && almaSourcePresets.ajax_url) || (typeof ajaxurl !== 'undefined' ? ajaxurl : ''); }
  function gygEsc(v){ return $('<div/>').text(v == null ? '' : String(v)).html(); }
  function gygSafeExcerpt(text){ text = String(text || '').replace(/([A-Z]:)?[\\/][^\s<>"']+/g, '[path]'); return text.substring(0, 500); }
  function gygClampQuantity(){ var $q = $('#alma-gyg-quantity'), value = parseInt($q.val(), 10) || 100; value = Math.max(1, Math.min(1000, value)); $q.val(value); return value; }
  function gygSetStatus(status){ $('.alma-gyg-status').show().removeClass('notice-error notice-success').addClass(status === 'Errore caricamento.' ? 'notice-error' : 'notice-info').html('<p>'+gygEsc(status)+'</p>'); }
  function gygShowError(message){ $('.alma-gyg-modal-error').show().find('p').first().text(message || 'Errore importazione.'); $('.alma-gyg-healthcheck,.alma-gyg-simple-link').show(); }
  function gygDisableImport(disabled){ $('.alma-gyg-start-import').prop('disabled', !!disabled); }
  function gygClearError(){ $('.alma-gyg-modal-error').hide().find('p').first().text(''); $('.alma-gyg-diagnostics,.alma-gyg-health-result').remove(); $('.alma-gyg-healthcheck,.alma-gyg-simple-link').hide(); }
  function gygAjaxErrorMessage(xhr, fallback){
    var text = xhr && xhr.responseText ? String(xhr.responseText).trim() : '';
    if(text === '0'){ return 'Errore AJAX: action non registrata oppure capability insufficiente.'; }
    if(text === '-1'){ return 'Nonce non valido o scaduto. Ricarica la pagina e riprova.'; }
    if(xhr && xhr.status === 403){ return 'HTTP 403: verifica di sicurezza o permessi non riusciti. Ricarica la pagina e riprova.'; }
    if(xhr && xhr.status === 400){ return 'HTTP 400: richiesta non valida. Verifica dati sessione e ricarica la pagina.'; }
    if(xhr && xhr.status >= 500){ return 'HTTP '+xhr.status+': errore server durante il caricamento del modale. Controlla i log PHP.'; }
    if(xhr && xhr.statusText === 'timeout'){ return 'Timeout AJAX: nessuna risposta entro 20 secondi. Controlla Network / admin-ajax.php e log PHP.'; }
    if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){ return xhr.responseJSON.data.message; }
    if(text){ try { var parsed = JSON.parse(text); if(parsed && parsed.data && parsed.data.message){ return parsed.data.message; } } catch(e) { return 'Risposta HTML/non JSON dal server. Controlla Network / admin-ajax.php e log PHP.'; } }
    return fallback || 'Risposta vuota o errore di rete. Ricarica la pagina e riprova.';
  }
  function gygDiagnostics(ctx, xhr, code, message){
    ctx = ctx || {}; xhr = xhr || {};
    var responseText = xhr.responseText ? gygSafeExcerpt(xhr.responseText) : '';
    var html = '<div class="alma-gyg-diagnostics"><h3>Diagnostica caricamento</h3><dl>'+ '<dt>Action AJAX</dt><dd>'+gygEsc(ctx.action || 'alma_gyg_csv_prepare_import')+'</dd>'+ '<dt>HTTP status</dt><dd>'+gygEsc(xhr.status || 'n/d')+'</dd>'+ '<dt>Source ID</dt><dd>'+gygEsc(ctx.sourceId || 'n/d')+'</dd>'+ '<dt>Token presente</dt><dd>'+(ctx.token ? 'sì' : 'no')+'</dd>'+ '<dt>Tipologia attività</dt><dd>'+gygEsc(ctx.activityType || 'n/d')+'</dd>'+ '<dt>Activity type hash presente</dt><dd>'+(ctx.activityHash ? 'sì' : 'no')+'</dd>'+ '<dt>Nonce presente</dt><dd>'+(gygNonce() ? 'sì' : 'no')+'</dd>'+ '<dt>Codice errore</dt><dd>'+gygEsc(code || 'n/d')+'</dd>'+ '<dt>Messaggio errore</dt><dd>'+gygEsc(message || 'n/d')+'</dd>'+(responseText ? '<dt>Estratto risposta</dt><dd><code>'+gygEsc(responseText)+'</code></dd>' : '')+'</dl></div>';
    $('.alma-gyg-diagnostics').remove(); $('.alma-gyg-modal-error').after(html);
  }
  function gygSafePayload(ctx){ return {action:ctx.action, nonce_present:!!gygNonce(), source_id:ctx.sourceId, token_present:!!ctx.token, activity_type:ctx.activityType, activity_type_hash_present:!!ctx.activityHash}; }
  function gygConsoleError(xhr, ctx, code, message, jsError){ if(window.console && console.error){ console.error('ALMA gyg_csv prepare import failed', {action:(ctx&&ctx.action)||'alma_gyg_csv_prepare_import', status:xhr&&xhr.status, responseText:gygSafeExcerpt(xhr&&xhr.responseText), payload:gygSafePayload(ctx||{}), code:code, message:message, jsError:jsError ? String(jsError.message || jsError) : ''}); } }
  function gygPrepareFailed(message, xhr, ctx, code, jsError){ $('.alma-gyg-summary').html('<p class="description">Impossibile caricare i dati del modale.</p>'); $('.alma-gyg-terms').html('<p class="description">Impossibile caricare le Tipologie Link Sothra. Il dettaglio errore è nella diagnostica.</p>'); $('.alma-gyg-preview').html('<p class="description">Anteprima non disponibile.</p>'); gygDisableImport(true); gygSetStatus('Errore caricamento.'); gygShowError(message); gygDiagnostics(ctx || (gygState || {}), xhr || {}, code, message); gygConsoleError(xhr || {}, ctx || gygState || {}, code, message, jsError); }
  function gygValidateBeforeAjax(ctx){ var missing=[]; if(!gygAjaxUrl()) missing.push('ajaxurl / ajax_url'); if(!gygNonce()) missing.push('gygNonce'); if(!ctx.sourceId || parseInt(ctx.sourceId,10)<1) missing.push('source_id'); if(!ctx.token) missing.push('token'); if(!ctx.activityType) missing.push('activity_type'); if(!ctx.activityHash) missing.push('activity_type_hash'); return missing; }
  function gygOpen(){ $('#alma-gyg-import-modal').addClass('is-open').attr('aria-hidden','false'); $('body').addClass('alma-modal-open'); $('.alma-gyg-js-version').attr('data-gyg-js-version', GYG_JS_VERSION).text('GYG CSV modal JS loaded: '+GYG_JS_VERSION); }
  function gygClose(){ if(gygState && gygState.running){ return; } $('#alma-gyg-import-modal').removeClass('is-open').attr('aria-hidden','true'); $('body').removeClass('alma-modal-open'); }
  function gygRenderPreview(items){ if(!items || !items.length){ $('.alma-gyg-preview').html('<p class="description">Nessun record disponibile per l’anteprima.</p>'); return; } var html = '<table class="widefat striped"><thead><tr><th>Stato</th><th>URL originale</th><th>URL affiliato</th><th>Città</th><th>Regione</th><th>Descrizione breve</th></tr></thead><tbody>'; items.forEach(function(it){ html += '<tr><td>'+gygEsc(it.status||'')+'</td><td><code>'+gygEsc(it.original_url||'')+'</code></td><td><code>'+gygEsc(it.affiliate_url||'')+'</code></td><td>'+gygEsc(it.city||'—')+'</td><td>'+gygEsc(it.region||'—')+'</td><td>'+gygEsc(it.description||'')+'</td></tr>'; }); $('.alma-gyg-preview').html(html+'</tbody></table>'); }
  function gygRenderTerms(terms, mapped){ mapped = mapped || []; if(!terms || !terms.length){ $('.alma-gyg-terms').html('<p class="description">Nessuna Tipologia Link Sothra disponibile nella tassonomia link_type. Crea almeno una Tipologia Link prima di importare.</p>'); gygDisableImport(true); return; } var html = '<div class="alma-gyg-term-list">'; terms.forEach(function(t){ var checked = mapped.indexOf(parseInt(t.id,10)) !== -1 ? ' checked' : ''; html += '<label><input type="checkbox" name="alma_gyg_terms[]" value="'+parseInt(t.id,10)+'"'+checked+'> '+gygEsc(t.name)+'</label>'; }); $('.alma-gyg-terms').html(html+'</div>'); }
  function gygSelectedTerms(){ return $('input[name="alma_gyg_terms[]"]:checked').map(function(){ return $(this).val(); }).get(); }
  function gygSetProgress(processed, requested, status){ var pct = requested ? Math.min(100, Math.round(processed / requested * 100)) : 0; $('.alma-progress-bar').css('width', pct+'%'); $('.alma-gyg-progress-status').text(status + ' ' + processed + ' / ' + requested + ' (' + pct + '%)'); }
  function gygAppendLogs(logs){ if(!logs || !logs.length) return; var $log = $('.alma-gyg-log'); if(!$log.find('ul').length) $log.html('<ul></ul>'); logs.forEach(function(msg){ $log.find('ul').append('<li>'+gygEsc(msg)+'</li>'); }); }
  function gygRenderReport(){ var a = gygState.aggregate; $('.alma-gyg-report').show().html('<h3>Report finale</h3><ul class="alma-gyg-report-list"><li><strong>Record selezionati:</strong> '+a.selected+'</li><li><strong>Record processati:</strong> '+a.processed+'</li><li><strong>Record importati:</strong> '+a.imported+'</li><li><strong>Record aggiornati:</strong> '+a.updated+'</li><li><strong>Già presenti saltati:</strong> '+a.existing+'</li><li><strong>Match deduplica non validi/stale:</strong> '+a.dedupe_matches_stale+'</li><li><strong>Record saltati:</strong> '+a.skipped+'</li><li><strong>Errori:</strong> '+a.errors+'</li><li><strong>URL non validi:</strong> '+a.invalid_urls+'</li><li><strong>Titoli letti da Titolo Attività:</strong> '+a.titles_read+'</li><li><strong>Titoli salvati nei Link affiliati:</strong> '+a.titles_saved+'</li><li><strong>Record senza Titolo Attività:</strong> '+a.titles_missing+'</li><li><strong>Contesti AI popolati:</strong> '+a.ai_contexts_populated+'</li><li><strong>Contesti AI saltati per policy manual_only:</strong> '+a.ai_contexts_skipped_manual_only+'</li><li><strong>Contesti AI non popolati:</strong> '+a.ai_contexts_missing+'</li><li><strong>Tipologie Link associate:</strong> '+a.link_types_associated+'</li><li><strong>Record senza città:</strong> '+a.without_city+'</li><li><strong>Record senza regione:</strong> '+a.without_region+'</li><li><strong>Quantità richiesta:</strong> '+a.requested+'</li><li><strong>Quantità effettivamente processata:</strong> '+a.effective_processed+'</li><li><strong>Durata stimata:</strong> '+a.duration.toFixed(2)+'s</li></ul>'); $('.alma-gyg-import-more').show(); }
  function gygImportNext(){ $.ajax({url:gygAjaxUrl(), method:'POST', dataType:'json', timeout:20000, data:{action:'alma_gyg_csv_import_batch', nonce:gygNonce(), source_id:gygState.sourceId, token:gygState.token, activity_type:gygState.activityType, activity_type_hash:gygState.activityHash, term_ids:gygState.termIds, quantity:gygState.quantity, cursor:gygState.cursor, update_existing:gygState.updateExisting ? 1 : 0}}).done(function(res){ if(!res || !res.success){ gygShowError((res && res.data && res.data.message) || 'Errore importazione.'); gygState.running=false; $('.alma-gyg-start-import').prop('disabled',false); return; } var d=res.data, a=gygState.aggregate; ['processed','effective_processed','imported','updated','existing','skipped','errors','invalid_urls','without_city','without_region','titles_read','titles_saved','titles_missing','ai_contexts_populated','ai_contexts_skipped_manual_only','ai_contexts_missing','link_types_associated','dedupe_matches_stale'].forEach(function(k){ a[k] += parseInt(d[k]||0,10); }); a.duration += parseFloat(d.duration||0); gygState.cursor = parseInt(d.next_cursor||gygState.cursor,10); gygAppendLogs(d.logs||[]); if(d.mapping_label){ $('.alma-gyg-mapping-cell').filter(function(){ return String($(this).attr('data-activity-hash')||'') === String(gygState.activityHash||'') || String($(this).attr('data-activity-type')||'') === String(gygState.activityType); }).text(d.mapping_label); } gygSetProgress(gygState.cursor, gygState.quantity, d.done ? 'Importazione completata:' : 'Importazione in corso:'); if(d.done){ gygState.running=false; $('.alma-gyg-start-import').prop('disabled',false).text('Avvia importazione'); gygRenderReport(); } else { gygImportNext(); } }).fail(function(xhr){ var msg = gygAjaxErrorMessage(xhr, 'Errore di rete durante l’importazione.'); gygShowError(msg); gygState.running=false; $('.alma-gyg-start-import').prop('disabled',false).text('Avvia importazione'); }); }
  $(document).on('input change','#alma-gyg-quantity',gygClampQuantity);
  $(document).on('click','.alma-modal-close',function(e){ e.preventDefault(); gygClose(); });
  $(document).on('click','.alma-gyg-open-import',function(e){
    e.preventDefault(); gygClearError();
    var $btn=$(this), activityType=String($btn.attr('data-activity-type')||''), activityHash=String($btn.attr('data-activity-hash')||''), sourceId=$btn.attr('data-source-id'), token=$btn.attr('data-token');
    gygState = {sourceId:sourceId, token:token, activityType:activityType, activityHash:activityHash, running:false, action:'alma_gyg_csv_prepare_import'};
    $('.alma-gyg-simple-link').attr('href', $btn.attr('data-simple-url') || '#');
    $('.alma-gyg-report').hide().empty(); $('.alma-gyg-log').html('<p class="description">Nessun errore o warning.</p>'); $('.alma-gyg-import-more').hide(); $('.alma-progress-bar').css('width','0%'); $('.alma-gyg-progress-wrap').hide(); $('.alma-gyg-diagnostics').remove();
    $('.alma-gyg-summary').html('<p>Dati importazione in lettura…</p>'); $('.alma-gyg-terms').html('<p class="description">Tipologie Link Sothra non ancora caricate.</p>'); $('.alma-gyg-preview').html('<p class="description">Anteprima non ancora caricata.</p>'); gygDisableImport(true); gygOpen(); gygSetStatus('Modale aperto.');
    gygSetStatus('Dati importazione letti.');
    var missing = gygValidateBeforeAjax(gygState);
    if(missing.length){ gygPrepareFailed('Dati mancanti prima della richiesta AJAX: '+missing.join(', ')+'.', {status:'n/d', responseText:''}, gygState, 'missing_client_data'); return; }
    gygSetStatus('Richiesta AJAX in preparazione.');
    var payload = {action:'alma_gyg_csv_prepare_import', nonce:gygNonce(), source_id:sourceId, token:token, activity_type:activityType, activity_type_hash:activityHash};
    gygSetStatus('Richiesta AJAX inviata.');
    $.ajax({url:gygAjaxUrl(), method:'POST', dataType:'json', timeout:20000, data:payload}).done(function(res, textStatus, xhr){
      gygSetStatus('Risposta ricevuta.');
      try {
        if(res === 0 || res === '0'){ gygPrepareFailed('Risposta 0 da admin-ajax.php: action non registrata o permessi insufficienti.', xhr || {status:0,responseText:'0'}, gygState, 'ajax_zero'); return; }
        if(res === -1 || res === '-1'){ gygPrepareFailed('Risposta -1 da admin-ajax.php: nonce non valido o scaduto.', xhr || {status:403,responseText:'-1'}, gygState, 'ajax_minus_one'); return; }
        if(!res){ gygPrepareFailed('Risposta AJAX vuota.', xhr || {status:0,responseText:''}, gygState, 'empty_response'); return; }
        if(!res.success){ var err=(res && res.data) || {}; gygPrepareFailed(err.message||'Impossibile preparare il modale.', xhr || {status:0, responseJSON:res}, gygState, err.code || 'json_success_false'); return; }
        var d=res.data || {}, c=d.counts||{}, terms=$.isArray(d.terms) ? d.terms : null;
        if(!terms){ gygPrepareFailed('Risposta AJAX non valida: Tipologie Link Sothra mancanti.', xhr || {status:200, responseJSON:res}, gygState, 'missing_terms_payload'); return; }
        if(!terms.length){ gygPrepareFailed('Nessuna Tipologia Link Sothra disponibile nella tassonomia link_type. Crea almeno una Tipologia Link.', xhr || {status:200, responseJSON:res}, gygState, 'empty_terms'); return; }
        var pr=d.progress||{}; $('.alma-gyg-summary').html('<dl class="alma-gyg-summary-grid"><dt>Tipologia CSV</dt><dd>'+gygEsc(d.activity_type)+'</dd><dt>Record totali</dt><dd>'+parseInt(c.total||0,10)+'</dd><dt>Record già importati</dt><dd>'+parseInt(c.existing||0,10)+'</dd><dt>Record ancora da importare</dt><dd>'+parseInt(c.remaining||0,10)+'</dd><dt>Progressi salvati</dt><dd>Importati '+parseInt(pr.imported_count||0,10)+' · aggiornati '+parseInt(pr.updated_count||0,10)+' · già presenti '+parseInt(pr.existing_count||0,10)+' · errori '+parseInt(pr.error_count||0,10)+'</dd><dt>Source attiva</dt><dd>'+(d.source_active?'Sì':'No')+'</dd><dt>Partner ID</dt><dd>'+gygEsc(d.partner_id||'—')+'</dd><dt>UTM medium</dt><dd>'+gygEsc(d.utm_medium||'—')+'</dd></dl>');
        gygRenderTerms(terms,d.mapped_term_ids||[]); gygRenderPreview($.isArray(d.preview) ? d.preview : []); gygDisableImport(false); gygSetStatus('Tipologie Link Sothra caricate.');
      } catch(ex) { gygPrepareFailed('Eccezione JavaScript durante il rendering del modale: '+(ex.message || ex), xhr || {status:200,responseJSON:res}, gygState, 'render_exception', ex); }
    }).fail(function(xhr){ var msg = gygAjaxErrorMessage(xhr, 'Impossibile caricare le Tipologie Link Sothra.'); var code=(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.code)||(xhr.statusText === 'timeout' ? 'timeout' : 'ajax_fail'); gygPrepareFailed(msg, xhr, gygState, code); });
  });
  $(document).on('click','.alma-gyg-healthcheck',function(e){ e.preventDefault(); if(!gygState) return; var $btn=$(this).prop('disabled',true).text('Test in corso…'); $('.alma-gyg-health-result').remove(); $.ajax({url:gygAjaxUrl(), method:'POST', dataType:'json', timeout:20000, data:{action:'alma_gyg_csv_modal_healthcheck', nonce:gygNonce(), source_id:gygState.sourceId, token:gygState.token, activity_type:gygState.activityType, activity_type_hash:gygState.activityHash}}).done(function(res){ $('.alma-gyg-diagnostics').after('<div class="alma-gyg-health-result"><h3>Risultato health check</h3><pre>'+gygEsc(JSON.stringify(res, null, 2))+'</pre></div>'); }).fail(function(xhr){ $('.alma-gyg-diagnostics').after('<div class="alma-gyg-health-result"><h3>Risultato health check</h3><pre>'+gygEsc(gygSafeExcerpt(xhr && xhr.responseText))+'</pre></div>'); }).always(function(){ $btn.prop('disabled',false).text('Test caricamento modale'); }); });
  $(document).on('click','.alma-gyg-start-import',function(e){ e.preventDefault(); gygClearError(); if(!gygState || gygState.running) return; var terms=gygSelectedTerms(); if(!terms.length){ gygShowError('Seleziona almeno una Tipologia Link Sothra prima di importare.'); return; } var quantity=gygClampQuantity(); gygState.termIds=terms; gygState.quantity=quantity; gygState.cursor=0; gygState.updateExisting=$('input[name="alma_gyg_update_existing"]:checked').val()==='1'; gygState.running=true; gygState.aggregate={selected:gygState.quantity,processed:0,effective_processed:0,imported:0,updated:0,existing:0,skipped:0,errors:0,invalid_urls:0,without_city:0,without_region:0,titles_read:0,titles_saved:0,titles_missing:0,ai_contexts_populated:0,ai_contexts_skipped_manual_only:0,ai_contexts_missing:0,link_types_associated:0,dedupe_matches_stale:0,requested:gygState.quantity,duration:0}; $('.alma-gyg-report').hide().empty(); $('.alma-gyg-log').html('<p class="description">Nessun errore o warning.</p>'); $('.alma-gyg-progress-wrap').show(); gygSetProgress(0, quantity, 'Preparazione importazione…'); $(this).prop('disabled',true).text('Importazione in corso…'); gygImportNext(); });
  $(document).on('click','.alma-gyg-import-more',function(e){ e.preventDefault(); $('.alma-gyg-report').hide().empty(); $('.alma-progress-bar').css('width','0%'); $('.alma-gyg-start-import').trigger('click'); });

  $(document).on('click','.alma-load-more-results',function(e){
    e.preventDefault();
    var $btn=$(this), url=$btn.data('href');
    if(url){ window.location.href=url; }
  });


  function gygSelectiveKey(){
    var $form = $('.alma-gyg-selective-import-form');
    if(!$form.length){ return ''; }
    return 'alma_gyg_selected_'+($form.find('input[name="source_id"]').val()||'')+'_'+($form.find('input[name="gyg_csv_token"]').val()||'')+'_'+($form.find('input[name="activity_type_hash"]').val()||'');
  }
  function gygSelectedIds(){
    var key = gygSelectiveKey(), ids = [];
    if(key && window.sessionStorage){ try { ids = JSON.parse(sessionStorage.getItem(key) || '[]') || []; } catch(e){ ids = []; } }
    $('.alma-gyg-row-select:checked').each(function(){ var v=String($(this).val()||''); if(v && ids.indexOf(v) === -1){ ids.push(v); } });
    return ids;
  }
  function gygStoreSelected(ids){
    ids = $.grep(ids || [], function(v, i){ return v && $.inArray(v, ids) === i; });
    var key = gygSelectiveKey();
    if(key && window.sessionStorage){ sessionStorage.setItem(key, JSON.stringify(ids)); }
    $('.alma-gyg-selected-store').empty();
    $.each(ids, function(_, id){ $('<input/>',{type:'hidden', name:'selected_external_ids[]', value:id}).appendTo('.alma-gyg-selected-store'); });
    $('.alma-gyg-import-selected').prop('disabled', ids.length < 1);
    $('.alma-gyg-no-selection-warning').toggle(ids.length < 1);
    return ids;
  }
  function gygRefreshSelected(){
    var ids = gygSelectedIds();
    $('.alma-gyg-row-select').each(function(){ $(this).prop('checked', ids.indexOf(String($(this).val()||'')) !== -1); });
    return gygStoreSelected(ids);
  }
  function gygAppendSelectedToContainer($container){
    $container.find('input.alma-gyg-selected-query').remove();
    $.each(gygStoreSelected(gygSelectedIds()), function(_, id){ $('<input/>',{type:'hidden', name:'selected_external_ids[]', value:id, 'class':'alma-gyg-selected-query'}).appendTo($container); });
  }
  function gygUrlWithSelected(url){
    var ids = gygStoreSelected(gygSelectedIds()), sep = url.indexOf('?') === -1 ? '?' : '&';
    $.each(ids, function(_, id){ url += sep + 'selected_external_ids[]=' + encodeURIComponent(id); sep='&'; });
    return url;
  }
  gygRefreshSelected();
  $(document).on('change', '.alma-gyg-row-select', function(){
    var id = String($(this).val()||''), ids = gygSelectedIds(), idx = ids.indexOf(id);
    if(this.checked && idx === -1){ ids.push(id); }
    if(!this.checked && idx !== -1){ ids.splice(idx, 1); }
    gygStoreSelected(ids);
  });
  $(document).on('click', '.alma-gyg-select-visible', function(e){ e.preventDefault(); var ids=gygSelectedIds(); $('.alma-gyg-row-select').each(function(){ var id=String($(this).val()||''); if(id && ids.indexOf(id)===-1){ ids.push(id); } $(this).prop('checked', true); }); gygStoreSelected(ids); });
  $(document).on('click', '.alma-gyg-deselect-visible', function(e){ e.preventDefault(); var visible=[]; $('.alma-gyg-row-select').each(function(){ visible.push(String($(this).val()||'')); $(this).prop('checked', false); }); var ids=$.grep(gygSelectedIds(), function(id){ return visible.indexOf(id)===-1; }); gygStoreSelected(ids); });
  $(document).on('click', '.alma-gyg-select-filtered', function(e){ e.preventDefault(); var ids=gygSelectedIds(), add=[]; $('.alma-gyg-filtered-id').each(function(){ var id=String($(this).val()||''); if(id){ add.push(id); } }); if(add.length > 100 && !window.confirm('Stai per selezionare '+add.length+' record filtrati. Confermi?')){ return; } $.each(add, function(_, id){ if(ids.indexOf(id)===-1){ ids.push(id); } }); $('.alma-gyg-row-select').prop('checked', true); gygStoreSelected(ids); });
  $(document).on('submit', '.alma-gyg-filter-form', function(){ gygAppendSelectedToContainer($(this)); });
  $(document).on('click', '.alma-gyg-pagination a, .alma-gyg-filter-box a.button', function(e){ e.preventDefault(); window.location.href = gygUrlWithSelected($(this).attr('href')); });
  $(document).on('submit', '.alma-gyg-selective-import-form', function(e){ var ids=gygStoreSelected(gygSelectedIds()); if(ids.length < 1){ e.preventDefault(); window.alert('Seleziona almeno un contenuto prima di importare.'); } });

  renderProviderFields();
  toggleSearchHints();
  updateSelected();
  syncResultFilters();
});
