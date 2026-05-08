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



  var gygState = null;
  function gygNonce(){ return (window.almaSourcePresets && almaSourcePresets.gygNonce) || ''; }
  function gygAjaxUrl(){ return (window.almaSourcePresets && almaSourcePresets.ajax_url) || (typeof ajaxurl !== 'undefined' ? ajaxurl : ''); }
  function gygEsc(v){ return $('<div/>').text(v == null ? '' : String(v)).html(); }
  function gygClampQuantity(){
    var $q = $('#alma-gyg-quantity'), value = parseInt($q.val(), 10) || 100;
    value = Math.max(1, Math.min(1000, value));
    $q.val(value);
    return value;
  }
  function gygShowError(message){ $('.alma-gyg-modal-error').show().find('p').text(message || 'Errore importazione.'); }
  function gygDisableImport(disabled){ $('.alma-gyg-start-import').prop('disabled', !!disabled); }
  function gygAjaxErrorMessage(xhr, fallback){
    var text = xhr && xhr.responseText ? String(xhr.responseText).trim() : '';
    if(text === '0'){ return 'Errore AJAX: action non registrata oppure capability insufficiente.'; }
    if(text === '-1'){ return 'Nonce non valido o scaduto. Ricarica la pagina e riprova.'; }
    if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){ return xhr.responseJSON.data.message; }
    if(text){
      try { var parsed = JSON.parse(text); if(parsed && parsed.data && parsed.data.message){ return parsed.data.message; } }
      catch(e) { return 'Risposta non JSON dal server. Controlla i log PHP o ricarica la pagina.'; }
    }
    if(xhr && xhr.status === 403){ return 'Verifica di sicurezza o permessi non riusciti. Ricarica la pagina e riprova.'; }
    if(xhr && xhr.status === 400){ return fallback || 'Richiesta non valida. Ricarica la pagina e riprova.'; }
    if(xhr && xhr.status >= 500){ return 'Errore server durante il caricamento del modale. Riprova tra poco.'; }
    return fallback || 'Errore di rete. Ricarica la pagina e riprova.';
  }
  function gygDiagnostics(ctx, xhr, code, message){
    ctx = ctx || {}; xhr = xhr || {};
    var html = '<div class="alma-gyg-diagnostics"><h3>Diagnostica caricamento</h3><dl>'+
      '<dt>Action AJAX</dt><dd>'+gygEsc(ctx.action || 'alma_gyg_csv_prepare_import')+'</dd>'+
      '<dt>HTTP status</dt><dd>'+gygEsc(xhr.status || 'n/d')+'</dd>'+
      '<dt>Source ID</dt><dd>'+gygEsc(ctx.sourceId || 'n/d')+'</dd>'+
      '<dt>Token presente</dt><dd>'+(ctx.token ? 'sì' : 'no')+'</dd>'+
      '<dt>Tipologia attività</dt><dd>'+gygEsc(ctx.activityType || 'n/d')+'</dd>'+
      '<dt>Codice errore</dt><dd>'+gygEsc(code || 'n/d')+'</dd>'+
      '<dt>Messaggio errore</dt><dd>'+gygEsc(message || 'n/d')+'</dd>'+
      '</dl></div>';
    $('.alma-gyg-diagnostics').remove();
    $('.alma-gyg-modal-error').after(html);
  }
  function gygPrepareFailed(message, xhr, ctx, code){
    $('.alma-gyg-summary').html('<p class="description">Impossibile caricare i dati del modale.</p>');
    $('.alma-gyg-terms').html('<p class="description">Impossibile caricare le Tipologie Link Sothra. Il dettaglio errore è nella diagnostica.</p>');
    $('.alma-gyg-preview').html('<p class="description">Anteprima non disponibile.</p>');
    gygDisableImport(true);
    gygShowError(message);
    gygDiagnostics(ctx || (gygState || {}), xhr || {}, code, message);
    if(window.console && console.error){ console.error('ALMA gyg_csv prepare import failed', {xhr:xhr, context:ctx || gygState, code:code, message:message}); }
  }
  function gygClearError(){ $('.alma-gyg-modal-error').hide().find('p').text(''); }
  function gygOpen(){ $('#alma-gyg-import-modal').addClass('is-open').attr('aria-hidden','false'); $('body').addClass('alma-modal-open'); }
  function gygClose(){ if(gygState && gygState.running){ return; } $('#alma-gyg-import-modal').removeClass('is-open').attr('aria-hidden','true'); $('body').removeClass('alma-modal-open'); }
  function gygRenderPreview(items){
    if(!items || !items.length){ $('.alma-gyg-preview').html('<p class="description">Nessun record disponibile per l’anteprima.</p>'); return; }
    var html = '<table class="widefat striped"><thead><tr><th>Stato</th><th>URL originale</th><th>URL affiliato</th><th>Città</th><th>Regione</th><th>Descrizione breve</th></tr></thead><tbody>';
    items.forEach(function(it){ html += '<tr><td>'+gygEsc(it.status||'')+'</td><td><code>'+gygEsc(it.original_url||'')+'</code></td><td><code>'+gygEsc(it.affiliate_url||'')+'</code></td><td>'+gygEsc(it.city||'—')+'</td><td>'+gygEsc(it.region||'—')+'</td><td>'+gygEsc(it.description||'')+'</td></tr>'; });
    $('.alma-gyg-preview').html(html+'</tbody></table>');
  }
  function gygRenderTerms(terms, mapped){
    mapped = mapped || [];
    if(!terms || !terms.length){ $('.alma-gyg-terms').html('<p class="description">Nessuna Tipologia Link Sothra disponibile. Creane almeno una prima di importare.</p>'); gygDisableImport(true); return; }
    var html = '<div class="alma-gyg-term-list">';
    terms.forEach(function(t){ var checked = mapped.indexOf(parseInt(t.id,10)) !== -1 ? ' checked' : ''; html += '<label><input type="checkbox" name="alma_gyg_terms[]" value="'+parseInt(t.id,10)+'"'+checked+'> '+gygEsc(t.name)+'</label>'; });
    $('.alma-gyg-terms').html(html+'</div>');
  }
  function gygSelectedTerms(){ return $('input[name="alma_gyg_terms[]"]:checked').map(function(){ return $(this).val(); }).get(); }
  function gygSetProgress(processed, requested, status){
    var pct = requested ? Math.min(100, Math.round(processed / requested * 100)) : 0;
    $('.alma-progress-bar').css('width', pct+'%');
    $('.alma-gyg-progress-status').text(status + ' ' + processed + ' / ' + requested + ' (' + pct + '%)');
  }
  function gygAppendLogs(logs){
    if(!logs || !logs.length) return;
    var $log = $('.alma-gyg-log');
    if(!$log.find('ul').length) $log.html('<ul></ul>');
    logs.forEach(function(msg){ $log.find('ul').append('<li>'+gygEsc(msg)+'</li>'); });
  }
  function gygRenderReport(){
    var a = gygState.aggregate;
    $('.alma-gyg-report').show().html('<h3>Report finale</h3><ul class="alma-gyg-report-list"><li><strong>Importati:</strong> '+a.imported+'</li><li><strong>Aggiornati:</strong> '+a.updated+'</li><li><strong>Già presenti:</strong> '+a.existing+'</li><li><strong>Saltati:</strong> '+a.skipped+'</li><li><strong>Errori:</strong> '+a.errors+'</li><li><strong>URL non validi:</strong> '+a.invalid_urls+'</li><li><strong>Record senza città:</strong> '+a.without_city+'</li><li><strong>Record senza regione:</strong> '+a.without_region+'</li><li><strong>Durata stimata:</strong> '+a.duration.toFixed(2)+'s</li></ul>');
    $('.alma-gyg-import-more').show();
  }
  function gygImportNext(){
    $.post(gygAjaxUrl(), {action:'alma_gyg_csv_import_batch', nonce:gygNonce(), source_id:gygState.sourceId, token:gygState.token, activity_type:gygState.activityType, activity_type_hash:gygState.activityHash, term_ids:gygState.termIds, quantity:gygState.quantity, cursor:gygState.cursor, update_existing:gygState.updateExisting ? 1 : 0})
      .done(function(res){
        if(!res || !res.success){ gygShowError((res && res.data && res.data.message) || 'Errore importazione.'); gygState.running=false; $('.alma-gyg-start-import').prop('disabled',false); return; }
        var d=res.data, a=gygState.aggregate;
        ['imported','updated','existing','skipped','errors','invalid_urls','without_city','without_region'].forEach(function(k){ a[k] += parseInt(d[k]||0,10); });
        a.duration += parseFloat(d.duration||0); gygState.cursor = parseInt(d.next_cursor||gygState.cursor,10); gygAppendLogs(d.logs||[]);
        if(d.mapping_label){ $('.alma-gyg-mapping-cell').filter(function(){ return String($(this).attr('data-activity-hash')||'') === String(gygState.activityHash||'') || String($(this).attr('data-activity-type')||'') === String(gygState.activityType); }).text(d.mapping_label); }
        gygSetProgress(gygState.cursor, gygState.quantity, d.done ? 'Importazione completata:' : 'Importazione in corso:');
        if(d.done){ gygState.running=false; $('.alma-gyg-start-import').prop('disabled',false).text('Avvia importazione'); gygRenderReport(); }
        else { gygImportNext(); }
      })
      .fail(function(xhr){ var msg = gygAjaxErrorMessage(xhr, 'Errore di rete durante l’importazione.'); gygShowError(msg); if(window.console && console.error){ console.error('ALMA gyg_csv import batch failed', {status: xhr && xhr.status, message: msg}); } gygState.running=false; $('.alma-gyg-start-import').prop('disabled',false).text('Avvia importazione'); });
  }
  $(document).on('input change','#alma-gyg-quantity',gygClampQuantity);
  $(document).on('click','.alma-modal-close',function(e){ e.preventDefault(); gygClose(); });
  $(document).on('click','.alma-gyg-open-import',function(e){
    e.preventDefault(); gygClearError();
    var $btn=$(this), activityType=String($btn.attr('data-activity-type')||''), activityHash=String($btn.attr('data-activity-hash')||''), sourceId=$btn.attr('data-source-id'), token=$btn.attr('data-token');
    gygState = {sourceId:sourceId, token:token, activityType:activityType, activityHash:activityHash, running:false, action:'alma_gyg_csv_prepare_import'};
    $('.alma-gyg-report').hide().empty(); $('.alma-gyg-log').html('<p class="description">Nessun errore o warning.</p>'); $('.alma-gyg-import-more').hide(); $('.alma-progress-bar').css('width','0%'); $('.alma-gyg-progress-wrap').hide();
    $('.alma-gyg-diagnostics').remove(); $('.alma-gyg-summary').html('<p>Richiesta in corso…</p>'); $('.alma-gyg-terms').html('<p class="description">Richiesta Tipologie Link Sothra in corso…</p>'); $('.alma-gyg-preview').html('<p class="description">Caricamento anteprima…</p>'); gygDisableImport(true); gygOpen();
    $.ajax({url:gygAjaxUrl(), method:'POST', dataType:'json', data:{action:'alma_gyg_csv_prepare_import', nonce:gygNonce(), source_id:sourceId, token:token, activity_type:activityType, activity_type_hash:activityHash}})
      .done(function(res){
        if(!res || !res.success){ var err=(res && res.data) || {}; gygPrepareFailed(err.message||'Impossibile preparare il modale.', {status:0, responseJSON:res}, gygState, err.code); return; }
        var d=res.data || {}, c=d.counts||{}, terms=$.isArray(d.terms) ? d.terms : null;
        if(!terms){ gygPrepareFailed('Risposta AJAX non valida: Tipologie Link Sothra mancanti.', {status:0, responseJSON:res}, gygState, 'missing_terms_payload'); return; }
        if(!terms.length){ gygPrepareFailed('Nessuna Tipologia Link Sothra disponibile nella tassonomia link_type.', {status:200, responseJSON:res}, gygState, 'empty_terms'); return; }
        var pr=d.progress||{}; $('.alma-gyg-summary').html('<dl class="alma-gyg-summary-grid"><dt>Tipologia CSV</dt><dd>'+gygEsc(d.activity_type)+'</dd><dt>Record totali</dt><dd>'+parseInt(c.total||0,10)+'</dd><dt>Record già importati</dt><dd>'+parseInt(c.existing||0,10)+'</dd><dt>Record ancora da importare</dt><dd>'+parseInt(c.remaining||0,10)+'</dd><dt>Progressi salvati</dt><dd>Importati '+parseInt(pr.imported_count||0,10)+' · aggiornati '+parseInt(pr.updated_count||0,10)+' · già presenti '+parseInt(pr.existing_count||0,10)+' · errori '+parseInt(pr.error_count||0,10)+'</dd><dt>Source attiva</dt><dd>'+(d.source_active?'Sì':'No')+'</dd><dt>Partner ID</dt><dd>'+gygEsc(d.partner_id||'—')+'</dd><dt>UTM medium</dt><dd>'+gygEsc(d.utm_medium||'—')+'</dd></dl>');
        gygRenderTerms(terms,d.mapped_term_ids||[]); gygRenderPreview($.isArray(d.preview) ? d.preview : []); gygDisableImport(!terms.length);
      })
      .fail(function(xhr){ var msg = gygAjaxErrorMessage(xhr, 'Impossibile caricare le Tipologie Link Sothra. Controlla che la tassonomia delle tipologie esista e ricarica la pagina.'); var code=(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.code)||''; gygPrepareFailed(msg, xhr, gygState, code); });
  });
  $(document).on('click','.alma-gyg-start-import',function(e){
    e.preventDefault(); gygClearError();
    if(!gygState || gygState.running) return;
    var terms=gygSelectedTerms(); if(!terms.length){ gygShowError('Seleziona almeno una Tipologia Link Sothra prima di importare.'); return; }
    var quantity=gygClampQuantity();
    gygState.termIds=terms; gygState.quantity=quantity; gygState.cursor=0; gygState.updateExisting=$('input[name="alma_gyg_update_existing"]:checked').val()==='1'; gygState.running=true;
    gygState.aggregate={imported:0,updated:0,existing:0,skipped:0,errors:0,invalid_urls:0,without_city:0,without_region:0,duration:0};
    $('.alma-gyg-report').hide().empty(); $('.alma-gyg-log').html('<p class="description">Nessun errore o warning.</p>'); $('.alma-gyg-progress-wrap').show(); gygSetProgress(0, quantity, 'Preparazione importazione…');
    $(this).prop('disabled',true).text('Importazione in corso…');
    gygImportNext();
  });
  $(document).on('click','.alma-gyg-import-more',function(e){ e.preventDefault(); $('.alma-gyg-report').hide().empty(); $('.alma-progress-bar').css('width','0%'); $('.alma-gyg-start-import').trigger('click'); });

  $(document).on('click','.alma-load-more-results',function(e){
    e.preventDefault();
    var $btn=$(this), url=$btn.data('href');
    if(url){ window.location.href=url; }
  });

  renderProviderFields();
  toggleSearchHints();
  updateSelected();
  syncResultFilters();
});
