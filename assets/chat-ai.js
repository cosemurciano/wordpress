jQuery(document).ready(function($){
    var frame = document.getElementById('alma-chat-frame');
    var doc = frame.contentDocument || frame.contentWindow.document;
    doc.open();
    doc.write('<style>body{font-family:inherit;margin:0;padding:10px;overflow-y:auto;} .msg{max-width:80%;margin:5px;padding:10px;border-radius:8px;} .user{background:#dcf8c6;margin-left:auto;text-align:right;} .ai{background:#f1f0f0;margin-right:auto;text-align:left;} .alma-affiliate-link{display:flex;align-items:center;text-decoration:none;margin-top:5px;} .alma-affiliate-img{width:80px;height:80px;border-radius:8px;object-fit:cover;margin-right:10px;} .alma-link-title{font-weight:bold;}</style><div id="messages"></div>');
    doc.close();
    var $messages = $(doc).find('#messages');
    var conversation = [];

    function escapeHtml(text){
        return $('<div/>').text(text).html();
    }

    $('#alma-chat-form').on('submit', function(e){
        e.preventDefault();
        var query = $('#alma-chat-query').val();
        if(!query){ return; }
        $('#alma-chat-query').val('');
        $messages.append('<div class="msg user">'+escapeHtml(query)+'</div>');
        doc.body.scrollTop = doc.body.scrollHeight;
        $.post(alma_chat_ai.ajax_url, {
            action: 'alma_affiliate_chat',
            nonce: alma_chat_ai.nonce,
            query: query,
            conversation: JSON.stringify(conversation)
        }).done(function(res){
            var html = res.success ? res.data.reply.replace(/\n/g,'<br>') : res.data;
            $messages.append('<div class="msg ai">'+html+'</div>');
            doc.body.scrollTop = doc.body.scrollHeight;
            if(res.success){
                conversation.push({role:'user',content:query});
                conversation.push({role:'assistant',content:res.data.reply});
            }
        }).fail(function(jqXHR, textStatus){
            $messages.append('<div class="msg ai">Errore: '+textStatus+'</div>');
            doc.body.scrollTop = doc.body.scrollHeight;
        });
    });
});
