// $Id: overlay-child.js,v 1.1 2009/12/02 07:28:22 webchick Exp $

(function ($) {

/**
 * Overlay object for child windows.
 */
Drupal.overlayChild = Drupal.overlayChild || { processed: false, behaviors: {} };

/**
 * Attach the child dialog behavior to new content.
 */
Drupal.behaviors.overlayChild = {
  attach: function (context, settings) {
    var self = Drupal.overlayChild;
    var settings = settings.overlayChild || {};

    // Make sure this behavior is not processed more than once.
    if (self.processed) {
      return;
    }
    self.processed = true;

    // If we cannot reach the parent window, then we have nothing else to do
    // here.
    if (!$.isObject(parent.Drupal) || !$.isObject(parent.Drupal.overlay)) {
      return;
    }

    // If a form has been submitted successfully, then the server side script
    // may have decided to tell us the parent window to close the popup dialog.
    if (settings.closeOverlay) {
      parent.Drupal.overlay.bindChild(window, true);
      // Close the child window from a separate thread because the current
      // one is busy processing Drupal behaviors.
      setTimeout(function () {
        // We need to store the parent variable locally because it will
        // disappear as soon as we close the iframe.
        var p = parent;
        p.Drupal.overlay.close(settings.args, settings.statusMessages);
        if (typeof settings.redirect == 'string') {
          p.Drupal.overlay.redirect(settings.redirect);
        }
      }, 1);
      return;
    }

    // If one of the regions displaying outside the overlay needs to be
    // reloaded, let the parent window know.
    if (settings.refreshRegions) {
      parent.Drupal.overlay.refreshRegions(settings.refreshRegions);
    }

    // Ok, now we can tell the parent window we're ready.
    parent.Drupal.overlay.bindChild(window);

    // If a form is being displayed, it has a hidden field for the parent
    // window's location. Pass it that information. Letting the server side
    // know the parent window's location lets us avoid unnecessary redirects
    // when the overlay window is being closed automatically.
    var re = new RegExp('^' + parent.Drupal.settings.basePath);
    var path = parent.window.location.pathname.replace(re, '');
    $('#edit-overlay-parent-url').val(path);

    // Install onBeforeUnload callback, if module is present.
    if ($.isObject(Drupal.onBeforeUnload) && !Drupal.onBeforeUnload.callbackExists('overlayChild')) {
      Drupal.onBeforeUnload.addCallback('overlayChild', function () {
        // Tell the parent window we're unloading.
        parent.Drupal.overlay.unbindChild(window);
      });
    }

    // Attach child related behaviors to the iframe document.
    self.attachBehaviors(context, settings);
  }
};

/**
 * Attach child related behaviors to the iframe document.
 */
Drupal.overlayChild.attachBehaviors = function (context, settings) {
  $.each(this.behaviors, function () {
    this(context, settings);
  });
};

/**
 * Scroll to the top of the page.
 *
 * This makes the overlay visible to users even if it is not as tall as the
 * previously shown overlay was.
 */
Drupal.overlayChild.behaviors.scrollToTop = function (context, settings) {
  window.scrollTo(0, 0);
};

/**
 * Modify links and forms depending on their relation to the overlay.
 *
 * By default, forms and links are assumed to keep the flow in the overlay.
 * Thus their action and href attributes respectively get a ?render=overlay
 * suffix. Non-administrative links should however close the overlay and
 * redirect the parent page to the given link. This would include links in a
 * content listing, where administration options are mixed with links to the
 * actual content to be shown on the site out of the overlay.
 *
 * @see Drupal.overlay.isAdminLink()
 */
Drupal.overlayChild.behaviors.parseLinks = function (context, settings) {
  $('a:not(.overlay-exclude)', context).once('overlay').each(function () {
    // Non-admin links should close the overlay and open in the main window.
    if (!parent.Drupal.overlay.isAdminLink(this.href)) {
      $(this).click(function () {
        // We need to store the parent variable locally because it will
        // disappear as soon as we close the iframe.
        var parentWindow = parent;
        if (parentWindow.Drupal.overlay.close(false)) {
          parentWindow.Drupal.overlay.redirect($(this).attr('href'));
        }
        return false;
      });
      return;
    }
    else {
      var href = $(this).attr('href');
      if (href.indexOf('http') > 0 || href.indexOf('https') > 0) {
        $(this).attr('target', '_new');
      }
      else {
        $(this).each(function(){
          this.href = parent.Drupal.overlay.fragmentizeLink(this);
        }).click(function () {
          parent.window.location.href = this.href;
          return false;
        });
      }
    }
  });
  $('form:not(.overlay-processed)', context).addClass('overlay-processed').each(function () {
    // Obtain the action attribute of the form.
    var action = $(this).attr('action');
    if (action.indexOf('http') != 0 && action.indexOf('https') != 0) {
      // Keep internal forms in the overlay.
      action += (action.indexOf('?') > -1 ? '&' : '?') + 'render=overlay';
      $(this).attr('action', action);
    }
    else {
      $(this).attr('target', '_new');
    }
  });
};

})(jQuery);
