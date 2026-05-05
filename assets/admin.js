(function () {
  'use strict';

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
})();
