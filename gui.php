<?php

require "utility.php";

if (!class_exists('gtk')) {
    die("Please load the php-gtk2 module in your php.ini\r\n");
}
 
function login(GtkWindow $wnd, GtkEntry $txtUsername, GtkEntry $txtPassword, $course_info)
{
    //fetch the values from the widgets into variables
    $strUsername = $txtUsername->get_text();
    $strPassword = $txtPassword->get_text();
 
    //Do some error checking
    $error = null;
    if (strlen($strUsername) == 0) {
      $error = "username is missing.\r\n";
    } else {
      $error = testLogin($strUsername, $strPassword);
    }
 
    if ($error !== null) {
      //We show a message box with the error
      $dialog = new GtkMessageDialog($wnd, Gtk::DIALOG_MODAL, Gtk::MESSAGE_ERROR, Gtk::BUTTONS_OK, $error);
      $dialog->set_markup("The following error occured:\r\n<span foreground='red'>" . $error . "</span>");
      $dialog->run();
      $dialog->destroy();

    } else {
      $handle = fopen("user_info", "w");
      fwrite($handle, "username:" . $strUsername . "\n"); 
      fwrite($handle, "password:" . $strPassword . "\n");
      fclose($handle);

      $wnd->hide();

      $wnd1 = new GtkWindow();
      $wnd1->set_title('MSync');
      $wnd1->set_position(Gtk::WIN_POS_CENTER);
      $wnd1->set_default_size(300, 150);
      $wnd1->connect_simple('destroy', array('gtk', 'main_quit'));

      $courses = fetchCourses();
      $num = count($courses);
      $checkBoxes = array();
      $lblDirs = array();
      $txtDirs = array();
      $editButtons = array();
      foreach ($courses as $course) {
        $checkBox = new GtkCheckButton($course['name']);
        $checkBox->set_active(true);
        $lblDir = new GtkLabel('dir:');
        $txtDir = new GtkEntry();
        if (count($course_info)) {
          $txtDir->set_text($course_info[$course['name']]['dir']);
          $checkBox->set_active($course_info[$course['name']]['checked']);
        }
        $editButton = new GtkButton('Edit');
        $editButton->connect_simple('clicked', 'selectFolder', $txtDir);
        $checkBoxes[] = $checkBox;
        $lblDirs[] = $lblDir;
        $txtDirs[] = $txtDir;
        $editButtons[] = $editButton;
      }

      $intro  = new GtkLabel('Courses on Ctools');
      $status = new GtkLabel('Thank you for using MSync!');
      $tbl1 = new GtkTable($num + 2, 4);
      $tbl1->attach($intro, 0, 4, 0, 1);
      for ($i = 0; $i < $num; $i++) {
        $tbl1->attach($checkBoxes[$i], 0, 1, $i + 1, $i + 2);
        $tbl1->attach($lblDirs[$i], 1, 2, $i + 1, $i + 2);
        $tbl1->attach($txtDirs[$i], 2, 3, $i + 1, $i + 2);
        $tbl1->attach($editButtons[$i], 3, 4, $i + 1, $i + 2);
      }
      $tbl1->attach($status, 0, 4, $num + 1, $num + 2);

      $btnc1 = new GtkButton('Sync');
      $btnc1->connect_simple('clicked', 'doSync', $wnd1, $checkBoxes, $txtDirs, $status, $courses);
      $btnc2 = new GtkButton('Quit');
      $btnc2->connect_simple('clicked', array('gtk', 'main_quit'));
      $bbox1 = new GtkHButtonBox();
      $bbox1->set_layout(Gtk::BUTTONBOX_SPREAD);
      $bbox1->add($btnc1);
      $bbox1->add($btnc2);
      $vbox1 = new GtkVBox();
      $vbox1->pack_start($tbl1);
      $vbox1->pack_start($bbox1);
      $wnd1->add($vbox1);
      $wnd1->show_all();
    }
}

function selectFolder(&$txtDir) {
  $dialog = new GtkFileChooserDialog("Select folder", null, Gtk::FILE_CHOOSER_ACTION_SELECT_FOLDER,
              array(Gtk::STOCK_OK, Gtk::RESPONSE_OK), null);
  $dialog->show_all();
  if ($dialog->run() == Gtk::RESPONSE_OK) {
    $selected_file = $dialog->get_filename();
    $txtDir->set_text($selected_file);
  }
  $dialog->destroy();
}

function doSync($wnd, $checkBoxes, $txtDirs, &$status, $courses) {
  $tmp = array();
  $handle = fopen("course_info", "w");
  for ($i = 0; $i < count($courses); $i++) {
    if ($checkBoxes[$i]->get_active()) {
      $courses[$i]['dir'] = $txtDirs[$i]->get_text();
      $tmp[] = $courses[$i];
    }
    $info = $courses[$i]['name'] . ":" . $txtDirs[$i]->get_text()
            . ":" . (int)$checkBoxes[$i]->get_active() . "\n";
    fwrite($handle, $info);
  }
  fclose($handle);
  $courses = $tmp;
  $error = syncResources($courses);

  if ($error) {
    $dialog = new GtkMessageDialog($wnd, Gtk::DIALOG_MODAL, Gtk::MESSAGE_ERROR, Gtk::BUTTONS_OK, $error);
    $dialog->set_markup("The following error occured:\r\n<span foreground='red'>" . $error . "</span>");
    $dialog->run();
    $dialog->destroy();
  } else {
    $msg = "Sync completed!\r\n";
    $dialog = new GtkMessageDialog($wnd, Gtk::DIALOG_MODAL, Gtk::MESSAGE_INFO, Gtk::BUTTONS_OK, $msg);
    $dialog->run();
    $dialog->destroy();
  }
  $wnd->destroy();
}

//Clear cookie
$cookie = "cookie.txt";
$handle = fopen($cookie, "w");
fclose($handle);

//Get saved user info
if (file_exists("user_info")) {
  $handle = fopen("user_info", "r");
  $content = fread($handle, filesize("user_info"));
  fclose($handle);
  $lines = split("\n", $content);
  if ($lines) {
    $username = split(":", $lines[0]);
    $username = $username[1];
    $password = split(":", $lines[1]);
    $password = $password[1];
  }
}

//Get saved course info
$course_info = array();
if (file_exists("course_info")) {
  $handle = fopen("course_info", "r");
  $content = fread($handle, filesize("course_info"));
  fclose($handle);
  $lines = split("\n", $content);
  array_pop($lines);
  foreach ($lines as $line) {
    $pieces = split(":", $line);
    $entry = array();
    $entry['dir'] = $pieces[1];
    $entry['checked'] = $pieces[2];
    $course_info[$pieces[0]] = $entry;
  }
}

//Create the login window
$wnd = new GtkWindow();
$wnd->set_title('Login');
$wnd->set_position(Gtk::WIN_POS_CENTER);
//Close the main loop when the window is destroyed
$wnd->connect_simple('destroy', array('gtk', 'main_quit'));
 
 
//Set up all the widgets we need
$lblCredit   = new GtkLabel('Login using your unique name and password');
$lblUsername = new GtkLabel('_Username', true);
$lblPassword = new GtkLabel('_Password', true);
$txtUsername = new GtkEntry();
if ($username) {
  $txtUsername->set_text($username);
}
$txtPassword = new GtkEntry();
if ($password) {
  $txtPassword->set_text($password);
}
$txtPassword->set_visibility(false);
$btnLogin    = new GtkButton('_Login');
$btnCancel   = new GtkButton('_Cancel');
 
 
$lblUsername->set_mnemonic_widget($txtUsername);
$lblPassword->set_mnemonic_widget($txtPassword);
 
//Destroy the window when the user clicks Cancel
$btnCancel->connect_simple('clicked', array($wnd, 'destroy'));
//Call the login function when the user clicks on Login
$btnLogin->connect_simple('clicked', 'login', $wnd, $txtUsername, $txtPassword, $course_info);
 
 
//Lay out all the widgets in the table
$tbl = new GtkTable(3, 2);
$tbl->attach($lblCredit, 0, 2, 0, 1);
$tbl->attach($lblUsername, 0, 1, 1, 2);
$tbl->attach($txtUsername, 1, 2, 1, 2);
$tbl->attach($lblPassword, 0, 1, 2, 3);
$tbl->attach($txtPassword, 1, 2, 2, 3);
 
 
//Add the buttons to a button box
$bbox = new GtkHButtonBox();
$bbox->set_layout(Gtk::BUTTONBOX_SPREAD);
$bbox->add($btnLogin);
$bbox->add($btnCancel);
 
 
//Add the table and the button box to a vbox
$vbox = new GtkVBox();
$vbox->pack_start($tbl);
$vbox->pack_start($bbox);
 
//Add the vbox to the window
$wnd->add($vbox);
//Show all widgets
$wnd->show_all();
//Start the main loop
Gtk::main();
?>
