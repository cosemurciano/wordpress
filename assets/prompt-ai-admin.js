jQuery(document).ready(function($){
    function togglePersonality(){
        if($('#alma-personality').val() === 'personalizzato'){
            $('#alma-personality-custom').show();
        } else {
            $('#alma-personality-custom').hide();
        }
    }
    togglePersonality();
    $('#alma-personality').on('change', togglePersonality);

    $('.nav-tab-wrapper .nav-tab').on('click', function(e){
        e.preventDefault();
        var ctx = $(this).data('context');
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.alma-context').hide();
        $('#context-' + ctx).show();
    });

    $('#alma-add-custom-prompt').on('click', function(e){
        e.preventDefault();
        var block = $('<div class="alma-custom-prompt">' +
            '<input type="text" name="custom_prompt_names[]" placeholder="Nome" />' +
            '<textarea name="custom_prompt_texts[]" rows="4" cols="60" placeholder="Prompt"></textarea>' +
            '<button class="button remove-custom-prompt">Elimina</button>' +
            '</div>');
        $('#alma-custom-prompts').append(block);
    });
    $('#alma-custom-prompts').on('click', '.remove-custom-prompt', function(e){
        e.preventDefault();
        $(this).parent().remove();
    });

    $('#alma-save-settings').on('click', function(e){
        e.preventDefault();
        var data = $('#alma-prompt-settings-form').serialize();
        data += '&action=alma_save_prompt_settings&nonce=' + alma_prompt_ai.nonce;
        $('#alma-save-settings').prop('disabled', true);
        $.post(alma_prompt_ai.ajax_url, data, function(resp){
            alert(resp.data);
            $('#alma-save-settings').prop('disabled', false);
        }).fail(function(){
            alert('Errore salvataggio');
            $('#alma-save-settings').prop('disabled', false);
        });
    });

    $('#alma-test-claude').on('click', function(e){
        e.preventDefault();
        var data = {
            action: 'alma_test_prompt',
            nonce: alma_prompt_ai.nonce,
            message: $('#alma-test-message').val(),
            context: $('#alma-test-context').val()
        };
        $('#alma-test-claude').prop('disabled', true);
        $('#alma-claude-response').text('...');
        $('#alma-final-prompt').text('');
        $.post(alma_prompt_ai.ajax_url, data, function(resp){
            if(resp.success){
                $('#alma-claude-response').text(resp.data.response);
                $('#alma-final-prompt').text(resp.data.prompt);
                var links = resp.data.links || {};
                var container = $('#alma-affiliate-links');
                container.empty();
                if(links.summary){
                    container.append($('<p>').text(links.summary));
                }
                if(Array.isArray(links.results)){
                    var list = $('<ul>');
                    links.results.forEach(function(item){
                        var li = $('<li>');
                        if(item.url){
                            li.append($('<a>').attr({href:item.url,target:'_blank'}).text(item.title));
                        } else {
                            li.text(item.title);
                        }
                        if(item.description){
                            li.append(' - ' + item.description);
                        }
                        list.append(li);
                    });
                    container.append(list);
                }
                $('#alma-test-result').show();
            } else {
                alert(resp.data);
            }
            $('#alma-test-claude').prop('disabled', false);
        }).fail(function(){
            alert('Errore test');
            $('#alma-test-claude').prop('disabled', false);
        });
    });
});
