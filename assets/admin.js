(function () {
  'use strict';

  var profileSelect = document.getElementById('alma-idea-instruction-profile');
  var saveProfileHidden = document.getElementById('alma-save-idea-instruction-profile-id');

  if (!profileSelect || !saveProfileHidden) {
    return;
  }

  var syncProfile = function () {
    saveProfileHidden.value = profileSelect.value;
  };

  syncProfile();
  profileSelect.addEventListener('change', syncProfile);
})();
