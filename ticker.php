<?php
// config variables
date_default_timezone_set('UTC');

$signs[] = '10.35.0.34:9520';
$initialize = false;
$debug = false;

// other important variables
$fileInfo = '/tmp/ticker.stats.txt';

// are we being asked to initialize from the command line?
if (isset($argv[1]) && $argv[1] == 'init') {
  $initialize = true;
  unset($argv);
}

// turn debug on when initializing or requested from command line
if ($initialize || isset($argv[1]) && $argv[1] == 'debug') {
  $debug = true;
  unset($argv);
}

// initialize signs if previous stats files don't exist
if (!file_exists($fileInfo)) {
  $initialize = true;
}

// initializing commands
$codeInit = '<0x01>Z00<0x02>';
$codeEnd = '<0x04>';
$codeNewLine = '<0x0D>';
$codeTimeStamp = '<0x13> <0x0B>0';
$codeContinuous = '<0x0E>00';
$codeNoSpaces = '<0x1B>&a';

// message styles
$styles = array('<0x1C>1', // [0] red
                '<0x1C>2', // [1] green
                '<0x1C>3', // [2] yellow
                '<0x1C>4', // [3] yellow, green, red from top to bottom
                '<0x1C>5', // [4] yellow, green, red from left to right
                '<0x1C>6', // [5] yellow, green, red diagonal 1
                '<0x1C>7', // [6] yellow, green, red diagonal 2
);

// incoming effects
$effectsIn = array('<0x1B>Ie', // [0] moves left to right
                   '<0x1B>If', // [1] moves right to left
                   '<0x1B>I6', // [2] scroll out to left
                   '<0x1B>I1', // [3] Squiggle
);

// outgoing effects
$effectsOut = array('<0x1B>Ox', // [0] Appears/Disappears in Dots
                   '<0x1B>Oe',  // [1] Moves Left to Right
                   '<0x1B>Of',  // [2] Moves Right to Left
                   '<0x1B>Ow',  // [3] Raining
);

// initialize variable
$cmds = array();
$total = 0;
$asap = 0;
$immediate = 0;
$daysLeft = 0;
$updateFile = false;

// should we initialize the ticker?
if ($initialize) {
  $cmds[] = $codeInit . 'E$$$$' . $codeEnd; // Clear Flash and RAM Memory
  $cmds[] = $codeInit . 'E.SLABC' . $codeEnd; // Set the playlist to file label A then B
  $cmds[] = $codeInit . 'E ' . date('His') . $codeEnd; // Set time
  $cmds[] = $codeInit . 'E;' . date('mdy') . $codeEnd; // Set day
  $cmds[] = $codeInit . "E'M" . $codeEnd; // Uses military time

  // C1 = Text color red
  // B0 = Background black
  // F1 = Normal font size
  // L1 = normal line spacing
  // T3 = 3 second pause between frames
  // S5 = fasest scroll speed
  // VC = horizontal justification
  // HF = vertical justification
  // W0 = Word Wrap off
  // DE = Default drive ram
  $cmds[] = $codeInit . 'E#C1B0F1L1T3S5VCHFW0DEMeOe' . $codeEnd;

  // line one
  $cmds[] = $codeInit . 'AA' . $styles[0] . 'T' . $styles[1] . '<0x10>T ' . $styles[0] . 'A' . $styles[1] . '<0x10>A ' . $styles[0] . 'I' . $styles[1] . '<0x10>I ' . $codeEnd;

  // display project deadlines
  $cmds[] = $codeInit . 'AB' . $styles[1] . 'DIY ' . $styles[3] . '<0x10>D' . $styles[1] . ' days!' . $codeEnd;

  // display the time
  $cmds[] = $codeInit . 'AC' . $styles[3] . $codeTimeStamp . $codeEnd;
}

// grab feed from live website
if (isset($argv[1])) {
  // continuous scroll
  $cmds[] = $codeInit . 'AB' . $effectsIn[1] . $effectsOut[2] . $styles[0] . $codeContinuous . $codeNoSpaces . $argv[1] . $codeEnd;
} else {
  $lines = file('/srv/redmine/public_html/config/database.yml', FILE_IGNORE_NEW_LINES);
  if ($lines) {
    $host = $username = $password = $database = '';

    foreach ($lines as $line) {
      if (preg_match('/username:\s(\w+)/', $line, $matches)) $username = $matches[1];
      if (preg_match('/password:\s(\w+)/', $line, $matches)) $password = $matches[1];
      if (preg_match('/database:\s(\w+)/', $line, $matches)) $database = $matches[1];
      if (preg_match('/host:\s+((?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)|\w+)/', $line, $matches)) $host = $matches[1];
    }

    if ($link = mysql_connect($host, $username, $password)) {
      if (mysql_select_db($database, $link)) {
        // grab ticket stats
        $total = mysql_result(mysql_query('SELECT COUNT(*) FROM issues WHERE status_id IN (1,2) AND project_id!=11'), 0);
        $asap = mysql_result(mysql_query('SELECT COUNT(*) FROM issues WHERE priority_id=6 AND status_id IN (1,2) AND project_id!=11'), 0);
        $immediate = mysql_result(mysql_query('SELECT COUNT(*) FROM issues WHERE priority_id=7 AND status_id IN (1,2) AND project_id!=11'), 0);

        // calculate days left until next project due date
        $dueDate = strtotime('2016-04-18 00:00:00');
        $daysLeft = floor(($dueDate - time()) / 3600 / 24);

        $previousInfo = (array) json_decode(@file_get_contents($fileInfo));

        // do we need to update the days left until project due date?
        if ((isset($previousInfo['daysLeft']) && $previousInfo['daysLeft'] != $daysLeft) || $initialize) {
          $cmds[] = $codeInit . 'GD' . $daysLeft . $codeEnd;
          $updateFile = true;
        }

        // do we need to update the ticket status?
        if ((isset($previousInfo['total']) && $previousInfo['total'] != $total) || (isset($previousInfo['asap']) && $previousInfo['asap'] != $asap) || (isset($previousInfo['immediate']) && $previousInfo['immediate'] != $immediate) || $initialize) {
          $cmds[] = $codeInit . 'GT' . $total . '<0x02>GA' . $asap . '<0x02>GI' . (($immediate>0)?'<0x07>1':'<0x07>0') . $immediate . $codeEnd;
          $updateFile = true;
        }
      } else {
        // display generic alert
        $cmds[] = $codeInit . 'AB' . 'INSERT 3 COINS' . $codeEnd;
      }
    } else {
      // display generic alert
      $cmds[] = $codeInit . 'AB' . 'INSERT 2 COINS' . $codeEnd;
    }
  } else {
    // display generic alert
    $cmds[] = $codeInit . 'AB' . 'INSERT COIN' . $codeEnd;
  }
}

// loop through all the signs and send the message
for ($x=0;$x<count($signs);$x++) {
  list($signIP, $signPort) = explode(':', $signs[$x]);
  if ($debug) {
    echo "Connecting to sign at $signIP ($signPort)\n";
  }

  // perform connection
  if (!$fp = @fsockopen($signIP, $signPort, $errno, $errstr, 5)) {
    if ($debug) {
      echo "> $errstr ($errno)\n";
      @unlink($fileInfo);
    }
  } else {
    // do we have commands to send?
    if (count($cmds) > 0) {
      for ($i=0;$i<count($cmds);$i++) {
        // remove any spaces
        $cmd = trim($cmds[$i]);

        if ($debug) {
          echo '> Sending command ' . ($i + 1) . ' of ' . count($cmds) . ': ' . $cmd . "\n";
        }

        // convert <0xXX> hex codes to characters
        $cmd = preg_replace_callback(
          '/\<0x(\w+)\>/',
          function ($matches) {
            return chr(hexdec($matches[1]));
          },
        $cmd);

        // write the command to the display
        fwrite($fp, $cmd);

        // wait two seconds for the unit to process this command
        sleep(2);
      }

      // a little extra time for the sign to process all commands
      sleep(2);
    }

    // close up shop
    fclose($fp);

    // save current ticket stats
    if ($updateFile) {
      $response = json_encode(array('total' => $total, 'asap' => $asap, 'immediate' => $immediate, 'daysLeft' => $daysLeft));
      file_put_contents($fileInfo, $response);

      if ($debug) {
        echo "> Updated " . $fileInfo . "\n";
      }
    }
  }
}
