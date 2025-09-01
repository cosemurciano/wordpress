jQuery(function($){
    var lines = [];

    function isValidUrl(url){
        try { new URL(url); return true; } catch(e){ return false; }
    }

    function parseInput(){
        var text = $('#alma-import-input').val();
        var rows = text.split(/\n/);
        lines = [];
        var total = 0, valid = 0;
        rows.forEach(function(row){
            if(row.trim() === '') return;
            total++;
            var parts = row.split('|');
            var title = parts[0] ? parts[0].trim() : '';
            var url = parts[1] ? parts[1].trim() : '';
            var error = '';
            if(!title){
                error = almaImport.msg_no_title;
            } else if(!url){
                error = almaImport.msg_no_url;
            } else if(!isValidUrl(url)){
                error = almaImport.msg_bad_url;
            }
            lines.push({title:title, url:url, error:error});
            if(!error) valid++;
        });
        $('#alma-line-count').text(total);
        $('#alma-valid-count').text(valid);
        $('#alma-error-count').text(total-valid);
    }

    function showStep(step){
        $('.alma-step').hide();
        $('#alma-step'+step).show();
        $('.alma-import-steps li').removeClass('active').eq(step-1).addClass('active');
    }

    function buildPreview(){
        var tbody = $('#alma-preview tbody');
        tbody.empty();
        var errors = 0;
        lines.forEach(function(item){
            var tr = $('<tr/>');
            tr.append($('<td/>').text(item.title));
            tr.append($('<td/>').text(item.url));
            tr.append($('<td/>').text(item.error));
            if(item.error){ tr.addClass('alma-error'); errors++; }
            tbody.append(tr);
        });
        $('#alma-total-preview').text(lines.length);
        $('#alma-valid-preview').text(lines.length - errors);
        $('#alma-error-preview').text(errors);
        $('#alma-to-step3').prop('disabled', errors > 0);
    }

    function startImport(status, types, rel, target){
        var total = lines.length;
        var current = 0, success = 0, errors = 0, duplicates = 0;
        $('#alma-progress-bar').css('width','0%');
        $('#alma-log').empty();
        $('#alma-final-stats').empty();
        $('#alma-restart').hide();

        function importNext(){
            if(current >= total){
                $('#alma-final-stats').html(
                    almaImport.msg_summary
                        .replace('%total%', total)
                        .replace('%success%', success)
                        .replace('%dup%', duplicates)
                        .replace('%err%', errors)
                );
                $('#alma-restart').show();
                return;
            }
            var item = lines[current];
            var data = {
                action: 'alma_import_affiliate_link',
                nonce: almaImport.nonce,
                title: item.title,
                url: item.url,
                status: status,
                types: types,
                rel: rel,
                target: target
            };
            $.post(almaImport.ajax_url, data, function(res){
                if(res.success){
                    success++;
                    var msg = almaImport.msg_success.replace('%s', '<a href="'+res.data.edit_link+'" target="_blank">'+item.title+'</a>');
                    $('#alma-log').append('<div class="success">'+msg+'</div>');
                } else if(res.data && res.data.code === 'duplicate'){
                    duplicates++;
                    var msgd = almaImport.msg_duplicate.replace('%s', item.url);
                    $('#alma-log').append('<div class="duplicate">'+msgd+'</div>');
                } else {
                    errors++;
                    var msge = almaImport.msg_error.replace('%s', item.title);
                    $('#alma-log').append('<div class="error">'+msge+'</div>');
                }
                current++;
                $('#alma-progress-bar').css('width', (current/total*100)+'%');
                importNext();
            }).fail(function(){
                errors++;
                current++;
                $('#alma-log').append('<div class="error">'+almaImport.msg_ajax+'</div>');
                $('#alma-progress-bar').css('width', (current/total*100)+'%');
                importNext();
            });
        }
        importNext();
    }

    // events
    $('#alma-import-input').on('input', parseInput);

    $('#alma-to-step2').on('click', function(e){
        e.preventDefault();
        parseInput();
        buildPreview();
        showStep(2);
    });

    $('#alma-back-step1').on('click', function(e){
        e.preventDefault();
        showStep(1);
    });

    $('#alma-to-step3').on('click', function(e){
        e.preventDefault();
        var status = $('#alma-import-status').val();
        var types = $('#alma-import-types input:checked').map(function(){ return $(this).val(); }).get();
        var rel = $('#alma-import-rel').val();
        var target = $('#alma-import-target').val();
        startImport(status, types, rel, target);
        showStep(3);
    });

    $('#alma-restart').on('click', function(){
        $('#alma-import-input').val('');
        parseInput();
        $('#alma-log').empty();
        $('#alma-final-stats').empty();
        $('#alma-restart').hide();
        showStep(1);
    });

    // initial
    parseInput();
});
