/**
 * One-time-code boxes: auto-advance, paste and arrow-key navigation.
 *
 * Progressive enhancement only. Without JavaScript the six inputs still submit
 * the code one digit per box; this file just removes the dead ends.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-otp-boxes]');
  if (!root) {
    return;
  }

  var boxes = Array.prototype.slice.call(root.querySelectorAll('input.otp-box'));
  if (boxes.length === 0) {
    return;
  }

  function digitsOnly(value) {
    return (value || '').replace(/[^0-9]/g, '');
  }

  function focusBox(index) {
    if (index < 0 || index >= boxes.length) {
      return;
    }
    boxes[index].focus();
    boxes[index].select();
  }

  function distribute(text, startIndex) {
    var digits = digitsOnly(text);
    var index = startIndex;

    for (var i = 0; i < digits.length && index < boxes.length; i += 1) {
      boxes[index].value = digits.charAt(i);
      index += 1;
    }

    focusBox(Math.min(index, boxes.length - 1));
  }

  boxes.forEach(function (box, index) {
    box.addEventListener('input', function () {
      var digits = digitsOnly(box.value);

      // Autofill and paste can drop the whole code into one box.
      if (digits.length > 1) {
        box.value = '';
        distribute(digits, index);
        return;
      }

      box.value = digits;
      if (digits.length === 1 && index < boxes.length - 1) {
        focusBox(index + 1);
      }
    });

    box.addEventListener('keydown', function (event) {
      if (event.key === 'Backspace') {
        if (box.value === '' && index > 0) {
          event.preventDefault();
          boxes[index - 1].value = '';
          focusBox(index - 1);
        }
        return;
      }

      if (event.key === 'ArrowLeft' && index > 0) {
        event.preventDefault();
        focusBox(index - 1);
        return;
      }

      if (event.key === 'ArrowRight' && index < boxes.length - 1) {
        event.preventDefault();
        focusBox(index + 1);
        return;
      }

      // Keep letters and symbols out of the code boxes.
      if (event.key.length === 1 && !/[0-9]/.test(event.key)) {
        event.preventDefault();
      }
    });

    box.addEventListener('paste', function (event) {
      var clipboard = event.clipboardData || window.clipboardData;
      var text = clipboard ? clipboard.getData('text') : '';
      if (!text) {
        return;
      }
      event.preventDefault();
      distribute(text, 0);
    });

    box.addEventListener('focus', function () {
      box.select();
    });
  });
})();
