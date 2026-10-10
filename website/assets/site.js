document.querySelectorAll('.copy').forEach(function (b) {
  b.addEventListener('click', function () {
    navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () {
      b.textContent = 'Copied!';
      setTimeout(function () { b.textContent = 'Copy'; }, 1500);
    });
  });
});
