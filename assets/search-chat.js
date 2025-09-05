jQuery( document ).ready( function( $ ) {
  $( '.alma-search-chat' ).each( function() {
    var container = $( this );
    var messages = container.find( '.alma-chat-messages' );
    var input    = container.find( '.alma-chat-input' );
    var button   = container.find( '.alma-chat-send' );

    function updateButton(){
      var hasText = $.trim( input.val() ).length > 0;
      button.prop( 'disabled', ! hasText );
      button.toggleClass( 'is-active', hasText );
    }

    input.on( 'input', updateButton );
    updateButton();

    function addMessage( content, cls ) {
      var div = $( '<div>' ).addClass( 'alma-msg ' + cls );
      if ( cls === 'bot' && almaChat.avatar ) {
        div.append( $( '<img>' ).addClass( 'alma-avatar' ).attr( 'src', almaChat.avatar ) );
      }
      var bubble = $( '<div>' ).addClass( 'alma-bubble' ).append( content );
      div.append( bubble );
      messages.append( div );
    }

    function isSafeUrl( url ) {
      try {
        var u = new URL( url );
        return u.protocol === 'http:' || u.protocol === 'https:';
      } catch ( e ) {
        return false;
      }
    }

    function handleError( msg ) {
      if ( msg ) {
        addMessage( $( '<div>' ).text( msg ), 'bot' );
      } else if ( almaChat.fallback ) {
        addMessage( $( '<div>' ).html( almaChat.fallback ), 'bot' );
      } else {
        addMessage( $( '<div>' ).text( almaChat.strings.error ), 'bot' );
      }
    }

    function send() {
      if ( button.prop( 'disabled' ) ) {
        return;
      }

      var text  = input.val();
      addMessage( $( '<div>' ).text( text ), 'user' );
      input.val( '' );
      updateButton();
      messages.scrollTop( messages[0].scrollHeight );

      button.addClass( 'is-loading' ).prop( 'disabled', true );

      $.post(
        almaChat.ajax_url,
        { action: 'alma_nl_search', nonce: almaChat.nonce, query: text },
        function( resp ) {
          if ( resp.success ) {
            var data = resp.data || {};

            if ( data.summary ) {
              addMessage( $( '<div>' ).text( data.summary ), 'bot' );
            }

            var grouped = {};
            ( data.results || [] ).forEach( function( item ) {
              var type = item.types && item.types.length ? item.types[0] : 'Altro';
              if ( ! grouped[ type ] ) {
                grouped[ type ] = [];
              }
              grouped[ type ].push( item );
            } );

            var hasResults = false;
            $.each( grouped, function( type, items ) {
              hasResults = true;
              addMessage( $( '<strong>' ).text( type ), 'bot-result' );
              items.forEach( function( it ) {
                var result = $( '<div>' ).addClass( 'alma-result' );

                if ( it.image && isSafeUrl( it.image ) && isSafeUrl( it.url ) ) {
                  var imgLink = $( '<a>' ).attr( { href: it.url, target: '_blank' } )
                    .append( $( '<img>' ).attr( { src: it.image, width: 80, height: 80 } ) );
                  result.append( imgLink );
                }

                var content = $( '<div>' ).addClass( 'alma-result-content' );

                if ( it.url && isSafeUrl( it.url ) ) {
                  var titleLink = $( '<a>' ).attr( { href: it.url, target: '_blank' } )
                    .append( $( '<h4>' ).text( it.title ) );
                  content.append( titleLink );
                } else {
                  content.append( $( '<h4>' ).text( it.title ) );
                }

                if ( it.description ) {
                  content.append( $( '<p>' ).text( it.description ) );
                }

                result.append( content );
                addMessage( result, 'bot-result' );
              } );
            } );

            if ( ! hasResults ) {
              var errMsg = data && ( typeof data === 'string' ? data : data.error );
              if ( errMsg ) {
                handleError( errMsg );
              } else if ( almaChat.ai_active ) {
                addMessage( $( '<div>' ).text( almaChat.strings.no_results ), 'bot' );
              } else if ( almaChat.fallback ) {
                addMessage( $( '<div>' ).html( almaChat.fallback ), 'bot' );
              }
            }
          } else {
            handleError( resp.data );
          }

          messages.scrollTop( messages[0].scrollHeight );
        }
      ).fail( function() {
        handleError();
        messages.scrollTop( messages[0].scrollHeight );
      } ).always( function() {
        button.removeClass( 'is-loading' ).addClass( 'is-success' );
        setTimeout( function(){
          button.removeClass( 'is-success' );
          updateButton();
        }, 500 );
      } );
    }

    container.on( 'click', '.alma-chat-send', send );
    container.on( 'keypress', '.alma-chat-input', function( e ) {
      if ( e.which === 13 ) {
        send();
        return false;
      }
    } );
  } );
} );

