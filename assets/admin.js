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

  var layoutPickers = document.querySelectorAll('.alma-layout-picker');

  if (!layoutPickers.length) {
    return;
  }

  var setCheckboxValue = function (form, name, value) {
    var field = form.querySelector('input[name="' + name + '"]');

    if (field) {
      field.checked = value === '1';
    }
  };

  var setSelectValue = function (form, name, value) {
    var field = form.querySelector('select[name="' + name + '"]');

    if (field) {
      field.value = value;
    }
  };

  var getFieldCheckedValue = function (form, name) {
    var field = form.querySelector('input[name="' + name + '"]');

    return field && field.checked ? '1' : '0';
  };

  var syncLayoutPreset = function (input) {
    var form = input.form;

    if (!form) {
      return;
    }

    setSelectValue(form, 'template_desktop_columns', input.dataset.desktopColumns || '1');
    setSelectValue(form, 'template_mobile_columns', input.dataset.mobileColumns || '1');
    setCheckboxValue(form, 'show_image', input.dataset.showImage || '0');
    setCheckboxValue(form, 'show_title', input.dataset.showTitle || '0');
    setCheckboxValue(form, 'show_content', input.dataset.showContent || '0');
    setCheckboxValue(form, 'show_button', input.dataset.showButton || '0');
  };

  var syncPresetFromAdvancedFields = function (form) {
    var selectedPreset = null;
    var presets = form.querySelectorAll('input[name="layout_preset"]');
    var desktopColumns = form.querySelector('select[name="template_desktop_columns"]');
    var mobileColumns = form.querySelector('select[name="template_mobile_columns"]');

    presets.forEach(function (preset) {
      var matches = desktopColumns && mobileColumns &&
        preset.dataset.desktopColumns === desktopColumns.value &&
        preset.dataset.mobileColumns === mobileColumns.value &&
        preset.dataset.showImage === getFieldCheckedValue(form, 'show_image') &&
        preset.dataset.showTitle === getFieldCheckedValue(form, 'show_title') &&
        preset.dataset.showContent === getFieldCheckedValue(form, 'show_content') &&
        preset.dataset.showButton === getFieldCheckedValue(form, 'show_button');

      if (matches) {
        selectedPreset = preset;
      }
    });

    presets.forEach(function (preset) {
      preset.checked = preset === selectedPreset;
    });
  };

  layoutPickers.forEach(function (picker) {
    var form = picker.closest('form');
    var advancedFields;

    picker.addEventListener('change', function (event) {
      if (event.target && event.target.matches('input[name="layout_preset"]')) {
        syncLayoutPreset(event.target);
      }
    });

    if (!form) {
      return;
    }

    advancedFields = form.querySelectorAll('select[name="template_desktop_columns"], select[name="template_mobile_columns"], input[name="show_image"], input[name="show_title"], input[name="show_content"], input[name="show_button"]');
    advancedFields.forEach(function (field) {
      field.addEventListener('change', function () {
        syncPresetFromAdvancedFields(form);
      });
    });
  });
})();
