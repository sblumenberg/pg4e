<?php

use \Tsugi\Util\U;
use \Tsugi\Util\PDOX;
use \Tsugi\Util\Mersenne_Twister;
use \Tsugi\Core\LTIX;

require_once "names.php";
require_once "courses.php";

function makeRoster($code,$course_count=false,$name_count=false) {
    global $names, $courses;
    $MT = new Mersenne_Twister($code);
    $retval = array();
    $cc = 0;
    foreach($courses as $k => $course) {
    $cc = $cc + 1;
    if ( $course_count && $cc > $course_count ) break;
        $new = $MT->shuffle($names);
        $new = array_slice($new,0,$MT->getNext(17,53));
        $inst = 1;
        $nc = 0;
        foreach($new as $k2 => $name) {
            $nc = $nc + 1;
            if ( $name_count && $nc > $name_count ) break;
            $retval[] = array($name, $course, $inst);
            $inst = 0;
        }
    }
    return $retval;
}

// Unique to user + course
function getCode($LAUNCH) {
    return $LAUNCH->user->id*42+$LAUNCH->context->id;
}

function getUnique($LAUNCH) {
    return md5($LAUNCH->user->key.'::'.$LAUNCH->context->key.
        '::'.$LAUNCH->user->id.'::'.$LAUNCH->context->id);
}

function getDbName($unique) {
    return substr("pg4e".$unique,0,15);
}

function getDbUser($unique) {
    return "pg4e_user_".substr($unique,15,5);
}

function getDbPass($unique) {
    return "pg4e_pass_".substr($unique,20,5);
}

/**
 * Returns
 * Object if good JSON was recceived.
 * String if something went wrong
 * Number if something went wrong and all we have is the http code
 */
function pg4e_request($dbname, $path='info/pg') {
    global $CFG, $pg4e_request_result, $pg4e_request_url;

    $pg4e_request_result = false;
    $pg4e_request_url = false;
    $pg4e_request_url = $CFG->pg4e_api_url.'/'.$path.'/'.$dbname;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $pg4e_request_url);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $CFG->pg4e_api_key.':'.$CFG->pg4e_api_password);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

    $pg4e_request_result = curl_exec($ch);
    if($pg4e_request_result === false)
    {
        return 'Curl error: ' . curl_error($ch);
    }
    $returnCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ( $returnCode != 200 ) return $returnCode;

    // It seems as though create success returns '"" '
    if ( $returnCode == 200 && trim($pg4e_request_result) == '""' ) return 200;

    // Lets parse the JSON
    $retval = json_decode($pg4e_request_result, false);  // As stdClass
    if ( $retval == null ) {
        error_log("JSON Error: ".json_last_error_msg());
        error_log($pg4e_request_result);
        return "JSON Error: ".json_last_error_msg();
    }
    return $retval;
}

function pg4e_extract_info($info) {
    $user = false;
    $password = false;
    $ip = false;
    try {
        $retval = new \stdClass();
         $retval->user = base64_decode($info->auth->data->POSTGRES_USER);
         $retval->password = base64_decode($info->auth->data->POSTGRES_PASSWORD);
         $retval->ip = $info->ing->status->loadBalancer->ingress[0]->ip ?? null;
         $retval->port = $info->svc->metadata->labels->port ?? null;
        return $retval;
    } catch(Exception $e) {
        return null;
    }
}

function pg4e_unlock_code($LAUNCH) {
    global $CFG;
    $unlock_code = md5(getUnique($LAUNCH) . $CFG->pg4e_unlock) ;
        return $unlock_code;
}

function pg4e_unlock_check($LAUNCH) {
    global $CFG;
    if ( $LAUNCH->context->key != '12345' ) return true;
    $unlock_code = pg4e_unlock_code($LAUNCH);
    if ( U::get($_COOKIE, 'unlock_code') == $unlock_code ) return true;
    return false;
}

function pg4e_unlock($LAUNCH) {
    global $CFG, $OUTPUT;
    if ( pg4e_unlock_check($LAUNCH) ) return true;

    $unlock_code = pg4e_unlock_code($LAUNCH);
    if ( U::get($_POST, 'unlock_code') == $CFG->pg4e_unlock ) {
        setcookie('unlock_code', $unlock_code);
        header("Location: ".addSession($_SERVER['REQUEST_URI']));
        return false;
    }
    $OUTPUT->header();
    $OUTPUT->bodyStart(false);
    $OUTPUT->topNav();
    ?>
<form method="post">
<p>Unlock code:
<input type="password" name="unlock_code">
<input type="submit">
</form>
<?php
    $OUTPUT->footer();
    return false;
}

function pg4e_user_db_load($LAUNCH) {
    global $CFG;
    global $pdo_database, $pdo_host, $pdo_port, $pdo_user, $pdo_pass, $info, $pdo_connection;

    if ( U::get($_POST,'default') ) {
                unset($_SESSION['pdo_host']);
                unset($_SESSION['pdo_port']);
                unset($_SESSION['pdo_database']);
                unset($_SESSION['pdo_user']);
                unset($_SESSION['pdo_pass']);
        header( 'Location: '.addSession('index.php') ) ;
        return false;
    }

    $unique = getUnique($LAUNCH);
    $project = getDbName($unique);

    $pdo_database = U::get($_POST, 'pdo_database');
    $pdo_host = U::get($_POST, 'pdo_host');
    $pdo_port = U::get($_POST, 'pdo_port');
    $pdo_user = U::get($_POST, 'pdo_user');
    $pdo_pass = U::get($_POST, 'pdo_pass');

    if ( $pdo_database && $pdo_host  && $pdo_user && $pdo_pass ) {
        setcookie("pdo_database", $pdo_database, time()+31556926 ,'/');
        setcookie("pdo_host", $pdo_host, time()+31556926 ,'/');
        setcookie("pdo_port", $pdo_port, time()+31556926 ,'/');
        setcookie("pdo_user", $pdo_user, time()+31556926 ,'/');
        setcookie("pdo_pass", $pdo_pass, time()+31556926 ,'/');
    } else {
        $pdo_database = U::get($_SESSION, 'pdo_database', U::get($_COOKIE, 'pdo_database', 'pg4e'));
        $pdo_host = U::get($_SESSION, 'pdo_host', U::get($_COOKIE, 'pdo_host'));
        $pdo_port = U::get($_SESSION, 'pdo_port', U::get($_COOKIE, 'pdo_port'));
        $pdo_user = U::get($_SESSION, 'pdo_user', U::get($_COOKIE, 'pdo_user', getDbUser($unique)));
        $pdo_pass = U::get($_SESSION, 'pdo_pass', U::get($_COOKIE, 'pdo_pass', getDbPass($unique)));
    }

    if ( strlen($pdo_port) < 1 ) $pdo_port = '5432';

    if ( ! $pdo_host && isset($CFG->pg4e_api_key) ) {
        $retval = pg4e_request($project, 'info/pg');
        $info = false;
        if ( is_object($retval) ) {
            $info = pg4e_extract_info($retval);
             if ( isset($info->ip) ) $pdo_host = $info->ip;
             if ( isset($info->port) ) $pdo_port = $info->port;
            $_SESSION['pdo_host'] = $pdo_host;
            $_SESSION['pdo_port'] = $pdo_port;
        }
    }

    // Store in the database...
    $json = $LAUNCH->result->getJSON();
    $new = json_encode(array(
        'pdo_host' => $pdo_host,
        'pdo_port' => $pdo_port,
        'pdo_database' => $pdo_database,
        'pdo_user' => $pdo_user,
        'pdo_pass' => $pdo_pass,
    ));
    if ( $new != $json ) $LAUNCH->result->setJSON($new);

	// Set up the cookies
    setcookie("pg4e_desc", $pdo_database, time()+31556926 ,'/');
    setcookie("pg4e_host", $pdo_host, time()+31556926 ,'/');
    setcookie("pg4e_port", $pdo_port, time()+31556926 ,'/');

    if ( $LAUNCH->user->instructor || ! isset($CFG->pg4e_api_key) ) {
        $_SESSION['pdo_host'] = $pdo_host;
        $_SESSION['pdo_port'] = $pdo_port;
        $_SESSION['pdo_database'] = $pdo_database;
        $_SESSION['pdo_user'] = $pdo_user;
        $_SESSION['pdo_pass'] = $pdo_pass;
    }

    if ( strlen($pdo_port) < 1 ) $pdo_port = 5432;
    $pdo_connection = "pgsql:host=$pdo_host;port=$pdo_port;dbname=$pdo_database";
    

    if ( ! $pdo_host && ! $LAUNCH->user->instructor ) {
        echo("<p>You have not yet set up your database server for project <b>".htmlentities($project)."</b></p>\n");
        echo("<p>Make sure to run the setup process before attempting this assignment..</p>\n");
        return false;
    }
    return true;
}

function pg4e_user_db_form($LAUNCH) {
	global $CFG;
    global $OUTPUT, $pdo_database, $pdo_host, $pdo_port, $pdo_user, $pdo_pass, $info, $pdo_connection;

        $tunnel = $LAUNCH->link->settingsGet('tunnel');
        if ( ! $pdo_host || strlen($pdo_host) < 1 ) {
                echo('<p style="color:red">It appears that your PostgreSQL environment is not yet set up or is not running.</p>'."\n");
    }
    if ( strlen($pdo_port) < 1 ) $pdo_port = "5432";
?>
<form name="myform" method="post" >
<p>
<?php if ( $LAUNCH->user->instructor || ! isset($CFG->pg4e_api_key) ) { ?>
Host: <input type="text" name="pdo_host" value="<?= htmlentities($pdo_host) ?>" id="pdo_host" onchange="setPGAdminCookies();"><br/>
Port: <input type="text" name="pdo_port" value="<?= htmlentities($pdo_port) ?>" id="pdo_port" onchange="setPGAdminCookies();"><br/>
Database: <input type="text" name="pdo_database" value="<?= htmlentities($pdo_database) ?>" id="pdo_database" onchange="setPGAdminCookies();"><br/>
User: <input type="text" name="pdo_user" value="<?= htmlentities($pdo_user) ?>"><br/>
Password: <span id="pass" style="display:none"><input type="text" name="pdo_pass" id="pdo_pass" value="<?= htmlentities($pdo_pass) ?>"/></span> (<a href="#" onclick="$('#pass').toggle();return false;">hide/show</a> <a href="#" onclick="copyToClipboard(this, '<?= htmlentities($pdo_pass) ?>');return false;">copy</a>) <br/>
</pre>
<script>
function setPGAdminCookies() {
    var host = $("#pdo_host").val();
    var port = $("#pdo_port").val();
    var database = $("#pdo_database").val();
    console.log(port, host, database);
    var now = new Date();
    var time = now.getTime();
    var expireTime = time + 1000*36000;
    now.setTime(expireTime);

    document.cookie = 'pg4e_desc='+database+';expires='+now.toGMTString()+';path=/;SameSite=Secure';
    document.cookie = 'pg4e_port='+port+';expires='+now.toGMTString()+';path=/;SameSite=Secure';
    document.cookie = 'pg4e_host='+host+';expires='+now.toGMTString()+';path=/;SameSite=Secure';
}
</script>
<?php } else { ?>
<p>
<pre>
Host: <?= $pdo_host ?>

Port: <?= $pdo_port ?>

Database: <?= $pdo_database ?>

Account: <?= $pdo_user ?>

Password: <span id="pass" style="display:none"><?= $pdo_pass ?></span> <input type="hidden" name="pdo_pass" id="pdo_pass" value="<?= htmlentities($pdo_pass) ?>"/> (<a href="#" onclick="$('#pass').toggle();return false;">hide/show</a> <a href="#" onclick="copyToClipboard(this, '<?= htmlentities($pdo_pass) ?>');return false;">copy</a>)
</pre>
</p>
<?php } ?>
<input type="submit" name="check" onclick="$('#submitspinner').show();return true;" value="Check Answer">
<img id="submitspinner" src="<?php echo($OUTPUT->getSpinnerUrl()); ?>" style="display:none">
<?php if ( $LAUNCH->user->instructor) { ?>
<input type="submit" name="default" value="Default Values">
<?php } ?>
</form>
</p>
<p>
<p>You can do basic SQL commands using the
<a href="<?= $CFG->apphome ?>/phppgadmin" target="_blank">Online PostgreSQL Client</a> in your browser.
For batch loading or to run Python programs, you will need to access to <b>psql</b> on the command line:</p>
<pre>
<?php if ( $tunnel == 'yes' ) { 
    $localport = $pdo_port;
    if ( $pdo_port < 10000 ) $localport = $pdo_port + 10000;
?>
You may need to set up SSH port forwarding through a server that you have access to
to connect to the database.  In one window, run

ssh -4 -L <?= htmlentities($localport) ?>:<?= htmlentities($pdo_host) ?>:<?= htmlentities($pdo_port) ?> your-account@your-login-server

In a second window, run:

psql -h 127.0.0.1 -p <?= htmlentities($localport) ?> -U <?= htmlentities($pdo_user) ?> <?= htmlentities($pdo_database) ?>

<!--
Python Notebook:
%load_ext sql
%config SqlMagic.autocommit=False
%sql postgres://<?= htmlentities($pdo_user) ?>:replacewithsecret@127.0.0.1:<?= htmlentities($pdo_port) ?>/<?= htmlentities($pdo_database) ?>
-->
If you have psql running somewhere that is not behind a firewall, use the command:
<?php } ?>
psql -h <?= htmlentities($pdo_host) ?> -p <?= htmlentities($pdo_port) ?> -U <?= htmlentities($pdo_user) ?> <?= htmlentities($pdo_database) ?>
<!--
Python Notebook:
%load_ext sql
%config SqlMagic.autocommit=False
%sql postgres://<?= htmlentities($pdo_user) ?>:replacewithsecret@<?= htmlentities($pdo_host) ?>:<?= htmlentities($pdo_port) ?>/<?= htmlentities($pdo_database) ?>
-->
</pre>
</p>
<?php
}

function pg4e_insert_meta($pg_PDO, $keystr, $valstr) {
    $pg_PDO->queryReturnError(
        "INSERT INTO pg4e_meta (keystr, valstr) VALUES (:keystr, :valstr)
                ON CONFLICT (keystr) DO UPDATE SET keystr=:keystr, updated_at=now();",
        array(":keystr" => $keystr, ":valstr" => $valstr)
    );
}

function pg4e_get_user_connection($LAUNCH, $pdo_connection, $pdo_user, $pdo_pass) {
    try {
        $pg_PDO = new PDOX($pdo_connection, $pdo_user, $pdo_pass,
        array(
            PDO::ATTR_TIMEOUT => 5, // in seconds
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        )
    );
    } catch(Exception $e) {
        $_SESSION['error'] = "Could not make database connection to ".$pdo_host." / ".$pdo_user
            ." | ".$e->getMessage();
        header( 'Location: '.addSession('index.php') ) ;
        return false;
    }
        return $pg_PDO;
}

function pg4e_check_debug_table($LAUNCH, $pg_PDO) {
    global $CFG;
    $sql = "SELECT id, query, result, created_at FROM pg4e_debug";
    $stmt = $pg_PDO->queryReturnError($sql);
    if ( ! $stmt->success ) {
        error_log("Sql Failure:".$stmt->errorImplode." ".$sql);
        $_SESSION['error'] = "SQL Query Error: ".$stmt->errorImplode;
        header( 'Location: '.addSession('index.php') ) ;
        return false;
    }
    $stmt = $pg_PDO->queryReturnError("DELETE FROM pg4e_debug");
    $stmt = $pg_PDO->queryReturnError(
        "INSERT INTO pg4e_debug (query, result) VALUES (:query, 'Success')",
        array(":query" => $sql)
    );
    $sql = "SELECT id, keystr, valstr FROM pg4e_meta";
    if ( ! pg4e_query_return_error($pg_PDO, $sql) ) {
        $_SESSION['error'] = "pg4e_debug exists, please create pg4e_meta";
        header( 'Location: '.addSession('index.php') ) ;
        return false;
    }

    $stmt = $pg_PDO->queryReturnError($sql);
    pg4e_insert_meta($pg_PDO, "user_id", $LAUNCH->user->id);
    pg4e_insert_meta($pg_PDO, "context_id", $LAUNCH->context->id);
    pg4e_insert_meta($pg_PDO, "key", $LAUNCH->context->key);
    $valstr = md5($LAUNCH->context->key.'::'.$CFG->pg4e_unlock).'::42::'.
                ($LAUNCH->user->id*42).'::'.($LAUNCH->context->id*42);

    $pg_PDO->queryDie(
        "INSERT INTO pg4e_meta (keystr, valstr) VALUES (:keystr, :valstr)
                ON CONFLICT (keystr) DO NOTHING;",
        array(":keystr" => "code", ":valstr" => $valstr)
    );
    return true;
}

function pg4e_debug_note($pg_PDO, $note) {
    $pg_PDO->queryReturnError(
        "INSERT INTO pg4e_debug (query, result) VALUES (:query, :result)",
        array(":query" => $note, ':result' => 'Note only')
    );
}

function pg4e_query_return_error($pg_PDO, $sql, $arr=false) {
    $stmt = $pg_PDO->queryReturnError($sql, $arr);
    if ( ! $stmt->success ) {
                $pg_PDO->queryReturnError(
                        "INSERT INTO pg4e_debug (query, result) VALUES (:query, :result)",
                        array(":query" => $sql, ':result' => $stmt->errorImplode)
                );
        error_log("Sql Failure:".$stmt->errorImplode." ".$sql);
        $_SESSION['error'] = "SQL Query Error: ".$stmt->errorImplode;
        header( 'Location: '.addSession('index.php') ) ;
        return false;
    }
        $pg_PDO->queryReturnError(
                "INSERT INTO pg4e_debug (query, result) VALUES (:query, 'Success')",
                array(":query" => $sql)
        );
        return $stmt;
}

function pg4e_grade_send($LAUNCH, $pg_PDO, $oldgrade, $gradetosend, $dueDate) {
    $scorestr = "Your answer is correct, score saved.";
    if ( $dueDate->penalty > 0 ) {
        $gradetosend = $gradetosend * (1.0 - $dueDate->penalty);
        $scorestr = "Effective Score = $gradetosend after ".$dueDate->penalty*100.0." percent late penalty";
    }
    if ( $oldgrade > $gradetosend ) {
        $scorestr = "New score of $gradetosend is < than previous grade of $oldgrade, previous grade kept";
        $gradetosend = $oldgrade;
    }

    // Use LTIX to send the grade back to the LMS.
    $debug_log = array();
    $retval = LTIX::gradeSend($gradetosend, false, $debug_log);
    $_SESSION['debug_log'] = $debug_log;

    if ( $retval === true ) {
        $_SESSION['success'] = $scorestr;
    } else if ( is_string($retval) ) {
        $scorestr = "Grade not sent: ".$retval;
        $_SESSION['error'] = $scorestr;
    } else {
                $scorestr = "Unexpected return: ".json_encode($retval);
                $_SESSION['error'] = "Unexpected return, see pg4e_result for detail";
    }
        $pg_PDO->queryReturnError(
        "INSERT INTO pg4e_result (link_id, score, note, title, debug_log)
                    VALUES (:link_id, :score, :note, :title, :debug_log)",
        array(":link_id" => $LAUNCH->link->id, ":score" => $gradetosend,
               ":note" => $scorestr, ":title" => $LAUNCH->link->title,
               ":debug_log" => json_encode($debug_log)
         )
    );
}

function pg4e_load_csv($filename) {
	$file = fopen($filename,"r");
	$retval = array();
	while ( $pieces = fgetcsv($file) ) {
    	$retval[] = $pieces;
	}
	fclose($file);
    return $retval;
}

function pg4e_user_es_load($LAUNCH) {
    global $CFG;
    global $es_host, $es_port, $es_user, $es_pass, $info, $es_connection;

    if ( U::get($_POST,'default') ) {
                unset($_SESSION['es_host']);
                unset($_SESSION['es_port']);
                unset($_SESSION['es_user']);
                unset($_SESSION['es_pass']);
        header( 'Location: '.addSession('index.php') ) ;
        return false;
    }

    $unique = getUnique($LAUNCH);
    $project = getDbName($unique);

    $es_host = U::get($_POST, 'es_host');
    $es_port = U::get($_POST, 'es_port');
    $es_user = U::get($_POST, 'es_user');
    $es_pass = U::get($_POST, 'es_pass');

    if ( $es_host  && $es_user && $es_pass ) {
        setcookie("es_host", $es_host, time()+31556926 ,'/');
        setcookie("es_port", $es_port, time()+31556926 ,'/');
        setcookie("es_user", $es_user, time()+31556926 ,'/');
        setcookie("es_pass", $es_pass, time()+31556926 ,'/');
    } else {
        $es_host = U::get($_SESSION, 'es_host', U::get($_COOKIE, 'es_host'));
        $es_port = U::get($_SESSION, 'es_port', U::get($_COOKIE, 'es_port'));
        $es_user = U::get($_SESSION, 'es_user', U::get($_COOKIE, 'es_user'));
        $es_pass = U::get($_SESSION, 'es_pass', U::get($_COOKIE, 'es_pass'));
    }

    if ( strlen($es_port) < 1 ) $es_port = '5432';

    if ( ! $es_host && isset($CFG->pg4e_api_key) ) {
        $retval = pg4e_request($project, 'info/es');
        $info = false;
        if ( is_object($retval) ) {
            $info = pg4e_extract_es_info($retval);
             if ( isset($info->ip) ) $es_host = $info->ip;
             if ( isset($info->port) ) $es_port = $info->port;
             if ( isset($info->user) ) $es_user = $info->user;
             if ( isset($info->password) ) $es_pass = $info->password;
            $_SESSION['es_host'] = $es_host;
            $_SESSION['es_port'] = $es_port;
            $_SESSION['es_user'] = $es_user;
            $_SESSION['es_pass'] = $es_pass;
        }
    }

    // Store in the database...
    $json = $LAUNCH->result->getJSON();
    $new = json_encode(array(
        'es_host' => $es_host,
        'es_port' => $es_port,
        'es_user' => $es_user,
        'es_pass' => $es_pass,
    ));
    if ( $new != $json ) $LAUNCH->result->setJSON($new);

    if ( $LAUNCH->user->instructor || ! isset($CFG->pg4e_api_key) ) {
        $_SESSION['es_host'] = $es_host;
        $_SESSION['es_port'] = $es_port;
        $_SESSION['es_user'] = $es_user;
        $_SESSION['es_pass'] = $es_pass;
    }

    if ( ! $es_host && ! $LAUNCH->user->instructor ) {
        echo("<p>You have not yet set up your database server for project <b>".htmlentities($project)."</b></p>\n");
        echo("<p>Make sure to run the setup process before attempting this assignment..</p>\n");
        return false;
    }
    return true;
}

function pg4e_extract_es_info($info) {
    $user = false;
    $password = false;
    $ip = false;
    try {
        $retval = new \stdClass();
         $retval->user = base64_decode($info->auth->data->ADMIN_USERNAME);
         $retval->password = base64_decode($info->auth->data->ADMIN_PASSWORD);
         $retval->ip = $info->ing->status->loadBalancer->ingress[0]->ip ?? null;
         $retval->port = $info->svc->metadata->labels->port ?? null;
        return $retval;
    } catch(Exception $e) {
        return null;
    }
}

function pg4e_user_es_form($LAUNCH) {
	global $CFG;
    global $OUTPUT, $es_host, $es_port, $es_user, $es_pass, $info, $es_connection;

        $tunnel = $LAUNCH->link->settingsGet('tunnel');
        if ( ! $es_host || strlen($es_host) < 1 ) {
                echo('<p style="color:red">It appears that your PostgreSQL environment is not yet set up or is not running.</p>'."\n");
    }
    if ( strlen($es_port) < 1 ) $es_port = "5432";
?>
<form name="myform" method="post" >
<p>
<?php if ( $LAUNCH->user->instructor || ! isset($CFG->pg4e_api_key) ) { ?>
Host: <input type="text" name="es_host" value="<?= htmlentities($es_host) ?>" id="es_host"><br/>
Port: <input type="text" name="es_port" value="<?= htmlentities($es_port) ?>" id="es_port"><br/>
User: <input type="text" name="es_user" value="<?= htmlentities($es_user) ?>"><br/>
Password: <span id="pass" style="display:none"><input type="text" name="es_pass" id="es_pass" value="<?= htmlentities($es_pass) ?>"/></span> (<a href="#" onclick="$('#pass').toggle();return false;">hide/show</a> <a href="#" onclick="copyToClipboard(this, '<?= htmlentities($es_pass) ?>');return false;">copy</a>) <br/>
</pre>
<?php } else { ?>
<p>
<pre>
Host: <?= $es_host ?>

Port: <?= $es_port ?>

Account: <?= $es_user ?>

Password: <span id="pass" style="display:none"><?= $es_pass ?></span> <input type="hidden" name="es_pass" id="es_pass" value="<?= htmlentities($es_pass) ?>"/> (<a href="#" onclick="$('#pass').toggle();return false;">hide/show</a> <a href="#" onclick="copyToClipboard(this, '<?= htmlentities($es_pass) ?>');return false;">copy</a>)
</pre>
</p>
<?php } ?>
<input type="submit" name="check" onclick="$('#submitspinner').show();return true;" value="Check Answer">
<img id="submitspinner" src="<?php echo($OUTPUT->getSpinnerUrl()); ?>" style="display:none">
<?php if ( $LAUNCH->user->instructor) { ?>
<input type="submit" name="default" value="Default Values">
<?php } ?>
</form>
</p>
</p>
<?php
}
