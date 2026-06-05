(function ($) {
    'use strict';

    $(function () {
        var profileSelect = document.getElementById('alma-idea-instruction-profile');
        var saveProfileHidden = document.getElementById('alma-save-idea-instruction-profile-id');
        var promptTextarea = document.getElementById('alma-openai-prompt');
        var savePromptHidden = document.getElementById('alma-save-idea-openai-prompt');

        if (profileSelect && saveProfileHidden) {
            var syncProfile = function () {
                saveProfileHidden.value = profileSelect.value;
            };
            syncProfile();
            profileSelect.addEventListener('change', syncProfile);
        }

        if (promptTextarea && savePromptHidden) {
            var syncPrompt = function () {
                savePromptHidden.value = promptTextarea.value;
            };
            syncPrompt();
            promptTextarea.addEventListener('input', syncPrompt);
            promptTextarea.addEventListener('change', syncPrompt);
        }

        $('.alma-copy-button').on('click', function () {
            var $button = $(this);
            var target = $button.data('copy-target');
            var text = $(target).text();

            if (!text) {
                return;
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text);
            } else {
                var $temp = $('<textarea>').val(text).appendTo('body').select();
                document.execCommand('copy');
                $temp.remove();
            }

            $button.text(window.almaAdmin && window.almaAdmin.copiedText ? window.almaAdmin.copiedText : 'Copiato');
        });
    });
})(jQuery);
