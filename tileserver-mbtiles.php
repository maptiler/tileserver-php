<?php

// Based on: https://github.com/Zverik/mbtiles-php
// Read: https://github.com/klokantech/tileserver-php/issues/1
// TODO: clean the code!!!

if (!is_file($_GET['tileset'])) {
  header('HTTP/1.0 404 Not Found');
  echo "<h1>404 Not Found</h1>";
  echo "TileServer.php could not found what you requested.";
  die();
  // TODO: if ($_GET['ext'] == 'png') { ...
  // TODO: better image 256x256px !!!
  // header("Content-type: image/png");
//print("\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDAT\x08\xd7c````\x00\x00\x00\x05\x00\x01^\xf3*:\x00\x00\x00\x00IEND\xaeB`\x82");
}
$tileset = $_GET['tileset'];

if (isset($_GET['tileset'])) {

  $tileset = $_GET['tileset'];
  $flip = true;
  try {
    $db = new PDO('sqlite:' . $tileset, '', '', array(PDO::ATTR_PERSISTENT => true));
    if (!isset($db)) {
      header('Content-type: text/plain');
      print 'Incorrect tileset name: ' . $_GET['tileset'];
      exit;
    }
    // http://c.tile.openstreetmap.org/12/2392/1190.png
    $z = floatval($_GET['z']);
    $y = floatval($_GET['y']);
    $x = floatval($_GET['x']);
    if ($flip) {
      $y = pow(2, $z) - 1 - $y;
    }
    if ($_GET['ext'] != 'grid' && $_GET['ext'] != 'json') {


      $result = $db->query('select tile_data as t from tiles where zoom_level=' . $z . ' and tile_column=' . $x . ' and tile_row=' . $y);
      $data = $result->fetchColumn();
      if (!isset($data) || $data === FALSE) {
        // TODO: Put here ready to use empty tile!!!
        $png = imagecreatetruecolor(256, 256);
        imagesavealpha($png, true);
        $trans_colour = imagecolorallocatealpha($png, 0, 0, 0, 127);
        imagefill($png, 0, 0, $trans_colour);
        header('Content-type: image/png');
        imagepng($png);
        //header($_SERVER['SERVER_PROTOCOL'].' 404 Not Found');
      } else {
        $result = $db->query('select value from metadata where name="format"');
        $resultdata = $result->fetchColumn();
        $format = isset($resultdata) && $resultdata !== FALSE ? $resultdata : 'png';
        if ($format == 'jpg')
          $format = 'jpeg';
        header('Content-type: image/' . $format);
        print $data;
      }
    } elseif ($_GET['ext'] == 'grid' || $_GET['ext'] == 'json') {
      //Get and return UTFgrid
      $result = $db->query('SELECT grid FROM grids WHERE tile_column = ' . $x . ' AND tile_row = ' . $y . ' AND zoom_level = ' . $z);
      $data = $result->fetchColumn();

      if (!isset($data) || $data === FALSE) {
        // if not exists grid data return empty json
        header('Access-Control-Allow-Origin: *');
        echo 'grid({});';
        die;
      } else {
        $grid = gzuncompress($data);
        $grid = substr(trim($grid), 0, -1);

        //adds legend (data) to output
        $grid .= ',"data":{';
        $result = $db->query('SELECT key_name as key, key_json as json FROM grid_data WHERE zoom_level=' . $z . ' and tile_column=' . $x . ' and tile_row=' . $y);
        while ($r = $result->fetch(PDO::FETCH_ASSOC)) {
          $grid .= '"' . $r['key'] . '":' . $r['json'] . ',';
        }
        $grid = rtrim($grid, ',') . '}}';

        // CORS header
        header('Access-Control-Allow-Origin: *');

        //TODO: Process callback and ext but first in htaccess or route
        if (isset($_GET['callback'])) {
          echo $_GET['callback'] . '(' . $grid . ');';
        } elseif ($_GET['ext'] == 'jsonp') {
          echo 'grid(' . $grid . ');';
        } else {
          echo $grid;
        }
        die;
      }
    }
  } catch (PDOException $e) {
    header('Content-type: text/plain');
    print 'Error querying the database: ' . $e->getMessage();
  }
}
/*
  function getbaseurl() {
  return 'http://'.$_SERVER['HTTP_HOST'].preg_replace('/\/(1.0.0\/)?[^\/]*$/','/',$_SERVER['REQUEST_URI']);
  }
 */

function readparams($db) {
  $params = array();
  $result = $db->query('select name, value from metadata');
  while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    $params[$row['name']] = $row['value'];
  }
  return $params;
}

function readzooms($db) {
  $zooms = array();
  $result = $db->query('select zoom_level from tiles group by zoom_level order by zoom_level');
  while ($zoom = $result->fetchColumn()) {
    $zooms[] = $zoom;
  }
  return $zooms;
}

?>
