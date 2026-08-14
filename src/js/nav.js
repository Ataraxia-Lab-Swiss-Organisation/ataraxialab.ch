(function () {
  var toggle = document.querySelector('.nav-toggle');
  var navMenu = document.querySelector('nav');
  if (toggle && navMenu) {
    toggle.addEventListener('click', function () {
      var open = navMenu.classList.toggle('open');
      this.setAttribute('aria-expanded', open);
    });
  }
}());
