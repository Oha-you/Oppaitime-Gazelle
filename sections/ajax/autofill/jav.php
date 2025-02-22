<?
$debug = false;

if (empty($_GET['cn'])) {
  json_die();
}

$cn = strtoupper($_GET['cn']);

if (!strpos($cn, '-')) {
  preg_match('/\d/', $cn, $m, PREG_OFFSET_CAPTURE);
  if ($m) { $cn = substr_replace($cn, '-', $m[0][1], 0); }
}

if (!$debug && $Cache->get_value('jav_fill_json_'.$cn)) {
  json_die('success', $Cache->get_value('jav_fill_json_'.$cn));
} else {

  $jlib_jp_url = ('http://www.javlibrary.com/ja/vl_searchbyid.php?keyword='.$cn);
  $jlib_en_url = ('http://www.javlibrary.com/en/vl_searchbyid.php?keyword='.$cn);
  $jdb_url     = ('http://javdatabase.com/movies/'.$cn.'/');

  $jlib_page_jp = file_get_contents($jlib_jp_url);
  $jlib_page_en = file_get_contents($jlib_en_url);
  $jdb_page     = file_get_contents($jdb_url);

  if ($jlib_page_en) {
    $jlib_dom_en = new DOMDocument();
    $jlib_dom_en->loadHTML($jlib_page_en);
    $jlib_en = new DOMXPath($jlib_dom_en);

    // Check if we're still on the search page and fix it if so
    if($jlib_en->query("//a[starts-with(@title, \"$cn\")]")->item(0)) {
      $href = substr($jlib_en->query("//a[starts-with(@title, \"$cn\")]")->item(0)->getAttribute('href'),1);
      $jlib_page_en = file_get_contents('http://www.javlibrary.com/en/'.$href);
      $jlib_page_jp = file_get_contents('http://www.javlibrary.com/ja/'.$href);
      $jlib_dom_en->loadHTML($jlib_page_en);
      $jlib_en = new DOMXPath($jlib_dom_en);
      // If the provided CN was so bad that search provided a different match, die
      if(strtoupper($jlib_en->query('//*[@id="video_id"]/table/tr/td[2]')->item(0)->nodeValue) != $cn) {
        json_die('failure', 'Movie not found');
      }
    }
  }
  if ($jlib_page_jp) {
    $jlib_dom_jp = new DOMDocument();
    $jlib_dom_jp->loadHTML($jlib_page_jp);
    $jlib_jp = new DOMXPath($jlib_dom_jp);
  }
  if ($jdb_page) {
    $jdb_dom = new DOMDocument();
    $jdb_dom->loadHTML($jdb_page);
    $jdb = new DOMXPath($jdb_dom);
  }

  list($idols, $genres, $screens, $title, $title_jp, $year, $studio, $label, $desc, $image) = array([],[],[],'','','','','','','');

  if (!$jdb_page && !$jlib_page_jp && !$jlib_page_en) {
    json_die('failure', 'Movie not found');
  }

  $degraded = false;

  if ($jlib_page_jp && $jlib_jp->query('//*[@id="video_title"]')['length']) {
    $title_jp = $jlib_jp->query('//*[@id="video_title"]/h3/a')->item(0)->nodeValue;
    $title_jp = substr($title_jp, strlen($cn) + 1);
  } else {
    $degraded = true;
  }
  if ($jlib_page_en && $jlib_en->query('//*[@id="video_title"]')['length']) {
    $title = $jlib_en->query('//*[@id="video_title"]/h3/a')->item(0)->nodeValue;
    $title = substr($title, strlen($cn) + 1);
    $idols = [];
    foreach ($jlib_en->query('//*[starts-with(@id, "cast")]/span[1]/a') as $idol) {
      $idols[] = $idol->nodeValue;
    }
    $year = $jlib_en->query('//*[@id="video_date"]/table/tr/td[2]')->item(0)->nodeValue;
    $year = explode('-', $year)[0];
    $studio = $jlib_en->query('//*[starts-with(@id, "maker")]/a')->item(0)->nodeValue;
    $label = $jlib_en->query('//*[starts-with(@id, "label")]/a')->item(0)->nodeValue;
    $image = $jlib_en->query('//*[@id="video_jacket_img"]')->item(0)->getAttribute('src');
    $comments = "";
    foreach ($jlib_en->query('//*[@class="comment"]//*[@class="t"]//textarea') as $comment) {
      $comments .= ($comment->nodeValue).' ';
    }
    preg_match_all("/\[img\b[^\]]*\]([^\[]*?)\[\/img\](?!\[\/url)/is", $comments, $screens_t);
    if (isset($screens_t[1])) {
      $screens = $screens_t[1];
      function f($s) { return !(preg_match('/(rapidgator)|(uploaded)|(javsecret)|(\.gif)|(google)|(thumb)|(imgur)|(fileboom)|(openload)/', $s)); }
      $screens = array_values(array_filter($screens, f));
    }
    if (preg_match('/http:\/\/imagetwist.com\/\S*jpg.html/', $comments, $twist)) {
      $twist_t = file_get_contents($twist[0]);
      $twist = new DOMDocument();
      $twist->loadHTML($twist_t);
      $twist = new DOMXPath($twist);
      if ($twist->query('//img[@class="pic"]')->item(0)) {
        $screens[] =  $twist->query('//img[@class="pic"]')->item(0)->getAttribute('src');
      }
    }
    $desc = '';
    $genres = [];
    foreach ($jlib_en->query('//*[starts-with(@id, "genre")]/a') as $genre) {
      $genres[] =  str_replace(' ', '.', strtolower($genre->nodeValue));
    }
  } else {
    $degraded = true;
  }
  if ($jdb_page) {
    if (!$title) {
      $title = trim($jdb->query("//b[contains(., 'Translated Title:')]")[0]->nextSibling->nodeValue);
    }
    if (!$studio) {
      $studio = $jdb->query("//b[contains(., 'Studio:')]")[0]->nextSibling->nodeValue;
    }
    if (!$label) {
      $label = $jdb->query("//b[contains(., 'Label:')]")[0]->nextSibling->nodeValue;
    }
    if (!$idols) {
      $idols_raw = $jdb->query("//b[contains(., 'Idol(s): ')]")[0]->nextSibling;

      for ($i = 0; $i < 10; $i++) {
        if ($idols_raw->tagName == "a") {
          $idol_name = $idols_raw->nodeValue;
          $idol_lower = strtolower(str_replace(' ', '-', $idol_name));
          // ensure it's actually an idol name
          if (strpos($idols_raw->attributes->item(0)->nodeValue, '.com/idols/' . $idol_lower) !== false) {
            $idols[] = implode(' ', array_reverse(explode(' ', $idols_raw->nodeValue)));
          }
        }
        $idols_raw = $idols_raw->nextSibling;
      }
    }
    if (!$year) {
      $year = substr($jdb->query("//b[contains(., 'Release Date:')]")[0]->nextSibling->nodeValue, 1, 4);
    }
    if (!$image) {
      $image = $jdb->query("//img[@alt='" . $cn . "']")->item(0)->getAttribute('src');
    }
    if (substr($image, 0, 2) == '//') {
      $image = 'https:'.$image;
    }
    if (!$desc) {
      //Shit neither of the sites have descriptions
      $desc = '';
    }
    if (!$genres) {
      // Mapping of JDB genres that are different to ours.
      $jdb_genre_map = [
        'Actress Best Compilation' => 'compilation',
        'Adultery' => 'cheating',
        'Anal Sex' => 'anal',
        'Big Tits' => 'big.breasts:female',
        'Big Tits Lover' => 'big.breasts:female',
        'Big Vibrator' => 'sex.toys',
        'Bunny Girl' => 'bunny.girl',
        'Cat Cosplay' => 'catgirl',
        'Cheating Wife' => 'cheating',
        'Chinese Dress' => 'chinese.dress',
        'Creampie' => 'nakadashi',
        'Cross Dresser' => 'crossdressing',
        'Cum Swallowing' => 'gokkun',
        'Deep Throat' => 'deepthroat',
        'Drunk Girl' => 'drunk',
        'Egg Vibrator' => 'sex.toys',
        'Face Sitting' => 'facesitting',
        'Female Ninja' => 'ninja',
        'Female Teacher' => 'teacher',
        'Gal' => 'gyaru:female',
        'Gay' => 'yaoi:male',
        'Golden Shower' => 'urination',
        'Huge Tits' => 'huge.breasts',
        'Hypnotism' => 'hypnosis',
        'Kiss Kiss' => 'kissing',
        'Leotards' => 'leotard',
        'Lesbian' => 'yuri:female',
        'Lesbian Kissing' => ['yuri:female', 'kissing'],
        'Lotion' => 'oil',
        'Massage Parlor' => 'massage',
        'MILF' => 'milf:female',
        'Muscular' => 'muscle',
        'Naked Apron' => 'apron',
        'Non-nude Erotica' => 'nonnude',
        'Office Lady' => 'office.lady:female',
        'Older Sister' => ['oneesan:female', 'sister:female'],
        'Orgy' => 'group',
        'Pregnant' => 'pregnant:female',
        'Private Tutor' => 'tutor',
        'Race Queen' => 'race.queen',
        'Relatives' => 'incest',
        'Sailor Uniform' => 'schoolgirl.uniform:female',
        'School Swimsuits' => 'school.swimsuit',
        'School Uniform' => 'schoolgirl.uniform:female',
        'Schoolgirl' => 'schoolgirl:female',
        'Sex Toys' => 'sex.toys',
        'Shaved Pussy' => 'shaved:female',
        'Shotacon' => 'shotacon:male',
        'Sister' => 'sister:female',
        'Small Tits' => 'small.breasts:female',
        'Substance Use' => 'drugs',
        'Swimsuits' => 'swimsuit',
        'Tall Girl' => 'tall.girl',
        'Threesome / Foursome' => 'threesome',
        'Titty Fuck' => 'paizuri',
        'Vibrator' => 'sex.toys',
        'Voyeur' => 'voyeurism',
        'Waitress' => 'waiter',
      ];
      foreach ($jdb->query("//a[@rel='tag' and starts-with(@href, 'https://www.javdatabase.com/genres/')]") as $tag) {
        if (array_key_exists($tag->nodeValue, $jdb_genre_map)) {
          if (is_array($jdb_genre_map[$tag->nodeValue])) {
            $genres = array_merge($genres, $jdb_genre_map[$tag->nodeValue]);
          } else {
            $genres[] = $jdb_genre_map[$tag->nodeValue];
          }
        } else {
          $genres[] = strtolower($tag->nodeValue);
        }
      }
    }
  }

  if (!($title || $idols || $year || $studio || $label || $genres)) {
    json_die('failure', 'Movie not found');
  }

  // Only show "genres" we have tags for
  if (!$Cache->get_value('genre_tags')) {
    $DB->query('
      SELECT Name
      FROM tags
      WHERE TagType = \'genre\'
      ORDER BY Name');
    $Cache->cache_value('genre_tags', $DB->collect('Name'), 3600 * 6);
  }
  $genres = array_values(array_intersect(array_values($Cache->get_value('genre_tags')), str_replace('_','.',array_values(Tags::remove_aliases(array('include' => str_replace('.','_',$genres)))['include']))));

  $json = array(
    'cn'          => $cn,
    'title'       => ($title ? $title : ''),
    'title_jp'    => ($title_jp ? $title_jp : ''),
    'idols'       => ($idols ? $idols : []),
    'year'        => ($year ? $year : ''),
    'studio'      => ($studio ? $studio : ''),
    'label'       => ($label ? $label : ''),
    'image'       => ($image ? $image : ''),
    'description' => ($desc ? $desc : ''),
    'tags'        => ($genres ? $genres : []),
    'screens'     => ($screens ? $screens : []),
    'degraded'    => $degraded
  );

  $Cache->cache_value('jav_fill_json_'.$cn, $json, 86400);

  json_die('success', $json);

}
