<?php
// $Id: user.api.php,v 1.16 2009/11/07 13:35:21 dries Exp $

/**
 * @file
 * Hooks provided by the User module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Act on user objects when loaded from the database.
 *
 * Due to the static cache in user_load_multiple() you should not use this
 * hook to modify the user properties returned by the {users} table itself
 * since this may result in unreliable results when loading from cache.
 *
 * @param $users
 *   An array of user objects, indexed by uid.
 *
 * @see user_load_multiple()
 * @see profile_user_load()
 */
function hook_user_load($users) {
  $result = db_query('SELECT * FROM {my_table} WHERE uid IN (:uids)', array(':uids' => array_keys($users)));
  foreach ($result as $record) {
    $users[$record->uid]->foo = $result->foo;
  }
}

/**
 * Act on user account cancellations.
 *
 * The user account is being canceled. Depending on the account cancellation
 * method, the module should either do nothing, unpublish content, anonymize
 * content, or delete content and data belonging to the canceled user account.
 *
 * Expensive operations should be added to the global batch with batch_set().
 *
 * @param $edit
 *   The array of form values submitted by the user.
 * @param $account
 *   The user object on which the operation is being performed.
 * @param $method
 *   The account cancellation method.
 *
 * @see user_cancel_methods()
 * @see hook_user_cancel_methods_alter()
 * @see user_cancel()
 */
function hook_user_cancel($edit, $account, $method) {
  switch ($method) {
    case 'user_cancel_block_unpublish':
      // Unpublish nodes (current revisions).
      module_load_include('inc', 'node', 'node.admin');
      $nodes = db_select('node', 'n')
        ->fields('n', array('nid'))
        ->condition('uid', $account->uid)
        ->execute()
        ->fetchCol();
      node_mass_update($nodes, array('status' => 0));
      break;

    case 'user_cancel_reassign':
      // Anonymize nodes (current revisions).
      module_load_include('inc', 'node', 'node.admin');
      $nodes = db_select('node', 'n')
        ->fields('n', array('nid'))
        ->condition('uid', $account->uid)
        ->execute()
        ->fetchCol();
      node_mass_update($nodes, array('uid' => 0));
      // Anonymize old revisions.
      db_update('node_revision')
        ->fields(array('uid' => 0))
        ->condition('uid', $account->uid)
        ->execute();
      // Clean history.
      db_delete('history')
        ->condition('uid', $account->uid)
        ->execute();
      break;

    case 'user_cancel_delete':
      // Delete nodes (current revisions).
      $nodes = db_select('node', 'n')
        ->fields('n', array('nid'))
        ->condition('uid', $account->uid)
        ->execute()
        ->fetchCol();
      foreach ($nodes as $nid) {
        node_delete($nid);
      }
      // Delete old revisions.
      db_delete('node_revision')
        ->condition('uid', $account->uid)
        ->execute();
      // Clean history.
      db_delete('history')
        ->condition('uid', $account->uid)
        ->execute();
      break;
  }
}

/**
 * Modify account cancellation methods.
 *
 * By implementing this hook, modules are able to add, customize, or remove
 * account cancellation methods. All defined methods are turned into radio
 * button form elements by user_cancel_methods() after this hook is invoked.
 * The following properties can be defined for each method:
 * - title: The radio button's title.
 * - description: (optional) A description to display on the confirmation form
 *   if the user is not allowed to select the account cancellation method. The
 *   description is NOT used for the radio button, but instead should provide
 *   additional explanation to the user seeking to cancel their account.
 * - access: (optional) A boolean value indicating whether the user can access
 *   a method. If #access is defined, the method cannot be configured as default
 *   method.
 *
 * @param &$methods
 *   An array containing user account cancellation methods, keyed by method id.
 *
 * @see user_cancel_methods()
 * @see user_cancel_confirm_form()
 */
function hook_user_cancel_methods_alter(&$methods) {
  // Limit access to disable account and unpublish content method.
  $methods['user_cancel_block_unpublish']['access'] = user_access('administer site configuration');

  // Remove the content re-assigning method.
  unset($methods['user_cancel_reassign']);

  // Add a custom zero-out method.
  $methods['mymodule_zero_out'] = array(
    'title' => t('Delete the account and remove all content.'),
    'description' => t('All your content will be replaced by empty strings.'),
    // access should be used for administrative methods only.
    'access' => user_access('access zero-out account cancellation method'),
  );
}

/**
 * Add mass user operations.
 *
 * This hook enables modules to inject custom operations into the mass operations
 * dropdown found at admin/people, by associating a callback function with
 * the operation, which is called when the form is submitted. The callback function
 * receives one initial argument, which is an array of the checked users.
 *
 * @return
 *   An array of operations. Each operation is an associative array that may
 *   contain the following key-value pairs:
 *   - "label": Required. The label for the operation, displayed in the dropdown menu.
 *   - "callback": Required. The function to call for the operation.
 *   - "callback arguments": Optional. An array of additional arguments to pass to
 *     the callback function.
 *
 */
function hook_user_operations() {
  $operations = array(
    'unblock' => array(
      'label' => t('Unblock the selected users'),
      'callback' => 'user_user_operations_unblock',
    ),
    'block' => array(
      'label' => t('Block the selected users'),
      'callback' => 'user_user_operations_block',
    ),
    'cancel' => array(
      'label' => t('Cancel the selected user accounts'),
    ),
  );
  return $operations;
}

/**
 * Retrieve a list of all user setting/information categories.
 *
 * @return
 *   A linear array of associative arrays. These arrays have keys:
 *   - "name": The internal name of the category.
 *   - "title": The human-readable, localized name of the category.
 *   - "weight": An integer specifying the category's sort ordering.
 */
function hook_user_categories() {
  return array(array(
    'name' => 'account',
    'title' => t('Account settings'),
    'weight' => 1,
  ));
}

/**
 * A user account is about to be created or updated.
 *
 * This hook is primarily intended for modules that want to store properties in
 * the serialized {users}.data column, which is automatically loaded whenever a
 * user account object is loaded, and the module needs to prepare the stored
 * data in some way.
 * The module should save its custom additions to the user object into the
 * database and set the saved fields to NULL in $edit.
 *
 * @param &$edit
 *   The array of form values submitted by the user.
 * @param $account
 *   The user object on which the operation is performed.
 * @param $category
 *   The active category of user information being edited.
 *
 * @see hook_user_insert()
 * @see hook_user_update()
 */
function hook_user_presave(&$edit, $account, $category) {
  // Make sure that our form value 'mymodule_foo' is stored as 'mymodule_bar'.
  if (isset($edit['mymodule_foo'])) {
    $edit['mymodule_bar'] = $edit['mymodule_foo'];
    // Inform user_save() to ignore the value of our property.
    $edit['mymodule_foo'] = NULL;
  }
}

/**
 * A user account was created.
 *
 * The module should save its custom additions to the user object into the
 * database and set the saved fields to NULL in $edit.
 *
 * @param &$edit
 *   The array of form values submitted by the user.
 * @param $account
 *   The user object on which the operation is being performed.
 * @param $category
 *   The active category of user information being edited.
 *
 * @see hook_user_presave()
 * @see hook_user_update()
 */
function hook_user_insert(&$edit, $account, $category) {
  db_insert('mytable')
    ->fields(array(
      'myfield' => $edit['myfield'],
      'uid' => $account->uid,
    ))
    ->execute();
  // Inform user_save() to ignore the value of our property.
  $edit['myfield'] = NULL;
}

/**
 * A user account was updated.
 *
 * Modules may use this hook to update their user data in a custom storage
 * after a user account has been updated.
 *
 * @param &$edit
 *   The array of form values submitted by the user.
 * @param $account
 *   The user object on which the operation is performed.
 * @param $category
 *   The active category of user information being edited.
 *
 * @see hook_user_presave()
 * @see hook_user_insert()
 */
function hook_user_update(&$edit, $account, $category) {
  db_insert('user_changes')
    ->fields(array(
      'uid' => $account->uid,
      'changed' => time(),
    ))
    ->execute();
}

/**
 * The user just logged in.
 *
 * @param &$edit
 *   The array of form values submitted by the user.
 * @param $account
 *   The user object on which the operation was just performed.
 */
function hook_user_login(&$edit, $account) {
  // If the user has a NULL time zone, notify them to set a time zone.
  if (!$user->timezone && variable_get('configurable_timezones', 1) && variable_get('empty_timezone_message', 0)) {
    drupal_set_message(t('Please configure your <a href="@user-edit">account time zone setting</a>.', array('@user-edit' => url("user/$user->uid/edit", array('query' => drupal_get_destination(), 'fragment' => 'edit-timezone')))));
  }
}

/**
 * The user just logged out.
 *
 * @param $account
 *   The user object on which the operation was just performed.
 */
function hook_user_logout($account) {
  db_insert('logouts')
    ->fields(array(
      'uid' => $account->uid,
      'time' => time(),
    ))
    ->execute();
}

/**
 * The user's account information is being displayed.
 *
 * The module should format its custom additions for display and add them to the
 * $account->content array.
 *
 * @param $account
 *   The user object on which the operation is being performed.
 * @param $build_mode
 *   Build mode, e.g. 'full'.
 */
function hook_user_view($account, $build_mode) {
  if (user_access('create blog content', $account)) {
    $account->content['summary']['blog'] =  array(
      '#type' => 'user_profile_item',
      '#title' => t('Blog'),
      '#markup' => l(t('View recent blog entries'), "blog/$account->uid", array('attributes' => array('title' => t("Read !username's latest blog entries.", array('!username' => format_username($account)))))),
      '#attributes' => array('class' => array('blog')),
    );
  }
}

/**
 * The user was built; the module may modify the structured content.
 *
 * This hook is called after the content has been assembled in a structured array
 * and may be used for doing processing which requires that the complete user
 * content structure has been built.
 *
 * If the module wishes to act on the rendered HTML of the user rather than the
 * structured content array, it may use this hook to add a #post_render callback.
 * Alternatively, it could also implement hook_preprocess_user_profile(). See
 * drupal_render() and theme() documentation respectively for details.
 *
 * @param $build
 *   A renderable array representing the user.
 *
 * @see user_build()
 */
function hook_user_build_alter($build) {
  // Check for the existence of a field added by another module.
  if (isset($build['an_additional_field'])) {
    // Change its weight.
    $build['an_additional_field']['#weight'] = -10;
  }

  // Add a #post_render callback to act on the rendered HTML of the user.
  $build['#post_render'][] = 'my_module_user_post_render';
}

/**
 * Inform other modules that a user role has been added.
 *
 * Modules implementing this hook can act on the user role object when saved to
 * the database. It's recommended that you implement this hook if your module
 * adds additional data to user roles object. The module should save its custom
 * additions to the database.
 *
 * @param $role
 *   A user role object.
 */
function hook_user_role_insert($role) {
  // Save extra fields provided by the module to user roles.
  db_insert('my_module_table')
    ->fields(array(
      'rid' => $role->rid,
      'role_description' => $role->description,
    ))
    ->execute();
}

/**
 * Inform other modules that a user role has been updated.
 *
 * Modules implementing this hook can act on the user role object when updated.
 * It's recommended that you implement this hook if your module adds additional
 * data to user roles object. The module should save its custom additions to
 * the database.
 *
 * @param $role
 *   A user role object.
 */
function hook_user_role_update($role) {
  // Save extra fields provided by the module to user roles.
  db_merge('my_module_table')
    ->key(array('rid' => $role->rid))
    ->fields(array(
      'role_description' => $role->description
    ))
    ->execute();
}

/**
 * Inform other modules that a user role has been deleted.
 *
 * This hook allows you act when a user role has been deleted.
 * If your module stores references to roles, it's recommended that you 
 * implement this hook and delete existing instances of the deleted role
 * in your module database tables.
 *
 * @param $role
 *   The $role object being deleted.
 */
function hook_user_role_delete($role) {
  // Delete existing instances of the deleted role.
  db_delete('my_module_table')
    ->condition('rid', $role->rid)
    ->execute();
}

/**
 * @} End of "addtogroup hooks".
 */
