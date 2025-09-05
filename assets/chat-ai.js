jQuery(document).ready(function($){
    $('#alma-chat-form').on('submit', function(e){
        e.preventDefault();
        var query = $('#alma-chat-query').val();
        var $resp = $('#alma-chat-response');
        $resp.text('...');
        $.post(alma_chat_ai.ajax_url, {
            action: 'alma_affiliate_chat',
            nonce: alma_chat_ai.nonce,
            query: query
        }).done(function(res){
            if(res.success){
                $resp.html(res.data.reply.replace(/\n/g,'<br>'));
            } else {
                $resp.text(res.data);
            }
        }).fail(function(jqXHR, textStatus){
            $resp.text('Errore: ' + textStatus);
        });
    });
});
