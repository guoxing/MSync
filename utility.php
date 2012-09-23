<?php

function checkCourse($course) {
  $ch = initCh();
  // get to the course homepage.
  curl_setopt($ch, CURLOPT_URL, $course['url']);
  $course_page = curl_exec($ch);  
  preg_match('/<a[^>]*?><span>Resources<\/span><\/a>/is', $course_page, $match);
  // no resource

  if (!$match) {
    return;
  }
  preg_match('/href="(.*?)"/is', $match[0], $match);
  curl_setopt($ch, CURLOPT_URL, $match['1']);
  $temp_page = curl_exec($ch);

  // get to the resources page.
  preg_match('/<div class="portletMainWrap">(.*?)<\/div>/is', $temp_page, $match);
  $main_div = $match[1];
  preg_match('/src="(.*?)"/is', $main_div, $match);
  $resource_url = $match[1];
  curl_setopt($ch, CURLOPT_URL, $resource_url);
  $resource_page = curl_exec($ch);

  // get to the pure resources page.
  preg_match('/<form name="showForm"[^>]*action="(.*?)"/is', $resource_page, $match);
  $pure_res_url = $match[1];
  curl_setopt($ch, CURLOPT_URL, $pure_res_url);
  $pure_res_page = curl_exec($ch);

  // Download!
  $cont = true;
  while ($cont) {
    preg_match_all('/<td headers="title"[^>]*>.*?<\/td>/is', $pure_res_page, $match);
    unset($match[0][0]);
    $cont = false;
    foreach ($match[0] as $entry) {
      preg_match('/href="(.*?)"/is', $entry, $match_url);
      if ($match_url[1] === "#") {

        preg_match('/<span class="nil">.*?onclick="(.*?)".*?<\/span>/is', $entry, $match_js);

        // no files under folder.
        if (!$match_js) {
          continue;
        }
        $statements = split(';', $match_js[1]);
        $reload = true;

        // get hidden input data from page.
        preg_match('/<form name="showForm"[^>]*>(.*?)<\/form>/is', $pure_res_page, $match_form);
        preg_match_all('/<input type="hidden" name="(.*?)"[^>]*?value="(.*?)"[^>\/]*\/>/is', $match_form[1], $match_input);

        foreach ($statements as $statement) {
          if (preg_match('/getelementbyid\(\'(.*?)\'\).value=\'(.*?)\'/is', $statement, $match_change)) {
            if ($match_change[1] === "sakai_action" && $match_change[2] !== "doExpand_collection") {
              $reload = false;
              break;
            }
            foreach ($match_input[1] as $idx => $name) {
              if ($name === $match_change[1]) {
                $match_input[2][$idx] = $match_change[2];
              }
            }
          }
        }
        if ($reload === false) {
          continue;
        }
        $cont = true;
        $postdata = "";
        for ($i = 0; $i < count($match_input[1]); $i++) {
          $postdata .= $match_input[1][$i] . '=' . $match_input[2][$i] . '&';
        }
        $postdata = substr($postdata, 0, -1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        $pure_res_page = curl_exec($ch);
      }
    }
  }
  curl_close($ch);

  preg_match_all('/<td headers="title"[^>]*>.*?<\/td>/is', $pure_res_page, $match_title);
  unset($match_title[0][0]);
  $file_depth = array();
  foreach ($match_title[0] as $title) {
    preg_match('/style="text-indent:(\d)em"/', $title, $match_depth);
    $file['depth'] = $match_depth[1];
    preg_match('/href="(.*?)"/is', $title, $match_url);
    $file['url'] = $match_url[1];
    preg_match('/<a[^>]*?>\s*([^<>]*?)\s*<\/a>/is', $title, $match_name);
    $file['name'] = $match_name[1];
    if ($file['url'] != '#') {
      preg_match('/[^\/]*$/', $file['url'], $match_name);
      preg_match('/\.[a-zA-Z0-9]+$/', $match_name[0], $match_ext);
      if ($match_ext) {
        preg_match('/\.[a-zA-Z0-9]+$/', $file['name'], $match_ori_ext);
        if ($match_ori_ext) {
          $file['name'] = substr($file['name'], 0, strpos($file['name'], $match_ori_ext[0]));
        }
        $file['name'] .= $match_ext[0];
      }
    }
    $file_depth[] = $file;
  }

  $idx = 0;
  $file_tree = buildFileTree($file_depth, $idx, 1);

  synchronize($file_tree, $course['dir']);
}

function buildFileTree(&$file_depth, &$idx, $depth) {
  $root = array();
  $local_idx = -1;
  while ($idx < count($file_depth)) {
    if ($file_depth[$idx]['depth'] > $depth) {
      $root[$local_idx]['children'] = buildFileTree($file_depth, $idx, $depth + 1);
    } else if ($file_depth[$idx]['depth'] == $depth) {
      $file['name'] = $file_depth[$idx]['name'];
      $file['url'] = $file_depth[$idx]['url'];
      $file['children'] = array();
      $root[] = $file;
      $local_idx++;
      $idx++;
    } else {
      return $root;
    }
  }
  return $root;
}

function synchronize($file_tree, $dir) {
  $local_dir = dir($dir);
  $entries = array();
  while (false !== ($entry = $local_dir->read())) {
    $entries[] = $entry;
  }
  foreach($file_tree as $child) {
    $find = false;
    $name = $dir . '/' . $child['name'];
    foreach ($entries as $entry) {
      if ($child['name'] === $entry) {
        $find = true;
        if ($child['children']) {
          synchronize($child['children'], $name);
        } else {
          break;
        }
      }
    }
    if (!$find) {
      if ($child['children']) {
        mkdir($name);
        synchronize($child['children'], $name);
      } else {
        download($child['url'], $name);
      }
    }
  }
}

function download($url, $name) {
  $ch = initCh();
  preg_match('/\.[a-zA-Z0-9]+$/', $name, $match_ext);
  if (!$match_ext || $match_ext[0] == '.URL') {
    return;
  }
  curl_setopt($ch, CURLOPT_TIMEOUT, 300);
  curl_setopt($ch, CURLOPT_URL, $url);
  $content = curl_exec($ch);
  file_put_contents($name, $content); 
  curl_close($ch);
}

function testLogin($username, $password) {
  $error = null;
  $ch = initCh();
  curl_setopt($ch, CURLOPT_URL, "https://ctools.umich.edu/portal/login");
  $page = curl_exec($ch);
  if (!preg_match('/input/', $page)) {
    $error = "Ctools is probably down!\r\n";
    return $error;
  }

  curl_setopt($ch, CURLOPT_URL, "https://weblogin.umich.edu/cosign-bin/cosign.cgi");
  $postdata = "ref=https://ctools.umich.edu/sakai-login-tool/container/cosign-bin/cosign.cgi&service=cosign-ctools&required=&login=" 
            . $username . "&password=" . $password . "&tokencode=";
  curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
  curl_setopt($ch, CURLOPT_POST, 1);
  $result = curl_exec($ch);
  curl_close($ch);
  preg_match('/my workspace/si', $result, $match);
  if (!$match) {
    $error = "Invalid username or password.\r\n";
  }
  return $error;
}

function fetchCourses() {
  $ch = initCh();
  curl_setopt($ch, CURLOPT_URL, "https://ctools.umich.edu/portal");
  $result = curl_exec($ch);
  preg_match('/<ul id="siteLinkList">(.*?)<\/ul>/is', $result, $course_list);
  preg_match_all('/<li>(.*?)<\/li>/is', $course_list[1], $courses_info);
  curl_close($ch);

  $courses = array();
  foreach ($courses_info[1] as $course_info) {
    preg_match('/<a href="(.*?)"[^>]*><span>(.*?)<\/span><\/a>/is', $course_info, $match);
    $course = array('name' => $match[2], 'url' => $match[1]);
    $courses[] = $course;
  }
  return $courses;
}

function initCh() {
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
  curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (X11; Linux i686) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/21.0.1180.89 Safari/537.1");
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_COOKIEJAR, "cookie.txt");
  curl_setopt($ch, CURLOPT_COOKIEFILE, "cookie.txt"); 
  curl_setopt($ch, CURLOPT_REFERER, "https://ctools.umich.edu");

  return $ch;
}

function syncResources($courses) {
  foreach ($courses as $course) {
    if (!is_dir($course['dir'])) {
      if (!mkdir($course['dir'])) {
        $error = "Cannot create the given directory\r\n";
        return $error;
      }
    }
    checkCourse($course);
  }
  return null;
}
?>
