jQuery(document).ready(function($){
  $('.alma-search-chat').each(function(){
    var container = $(this);
    var messages = container.find('.alma-chat-messages');
    function addMessage(content, cls){
      var div = $('<div>').addClass('alma-msg '+cls);
      if(cls==='bot' && almaChat.avatar){
        div.append($('<img>').addClass('alma-avatar').attr('src', almaChat.avatar));
      }
      var bubble = $('<div>').addClass('alma-bubble').append(content);
      div.append(bubble);
      messages.append(div);
    }
    function send(){
      var input = container.find('.alma-chat-input');
      var text = input.val();
      if(!text){return;}
      addMessage($('<div>').text(text),'user');
      input.val('');
      messages.scrollTop(messages[0].scrollHeight);
      $.post(almaChat.ajax_url,{action:'alma_nl_search',nonce:almaChat.nonce,query:text},function(resp){
        if(resp.success){
          var data = resp.data || {};
          if(data.summary){
            addMessage($('<div>').text(data.summary),'bot');
          }
          var grouped = {};
          (data.results || []).forEach(function(item){
            var type = item.types && item.types.length ? item.types[0] : 'Altro';
            if(!grouped[type]) grouped[type]=[];
            grouped[type].push(item);
          });
          var hasResults = false;
          $.each(grouped,function(type,items){
            hasResults = true;
            addMessage($('<strong>').text(type),'bot-result');
            items.forEach(function(it){
              var result = $('<div>').addClass('alma-result');
              if(it.image){
                var imgLink = $('<a>').attr({href:it.url,target:'_blank'})
                  .append($('<img>').attr({src:it.image,width:80,height:80}));
                result.append(imgLink);
              }
              var content = $('<div>').addClass('alma-result-content');
              var titleLink = $('<a>').attr({href:it.url,target:'_blank'})
                .append($('<h4>').text(it.title));
              content.append(titleLink);
              if(it.description){
                content.append($('<p>').text(it.description));
              }
              result.append(content);
              addMessage(result,'bot-result');
            });
          });
          if(!hasResults){
            if(almaChat.ai_active){
              addMessage($('<div>').text(almaChat.strings.no_results),'bot');
            } else if(almaChat.fallback){
              addMessage($('<div>').html(almaChat.fallback),'bot');
            }
          }
        } else {
          if(almaChat.ai_active){
            addMessage($('<div>').text(resp.data || almaChat.strings.error),'bot');
          } else if(almaChat.fallback){
            addMessage($('<div>').html(almaChat.fallback),'bot');
          } else {
            addMessage(resp.data || almaChat.strings.error,'bot');
          }
        }
        messages.scrollTop(messages[0].scrollHeight);
      });
    }
    container.on('click','.alma-chat-send',send);
    container.on('keypress','.alma-chat-input',function(e){ if(e.which===13){send(); return false;} });
  });
});
