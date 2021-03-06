<?php
use \Tsugi\Util\Net;
use \Tsugi\Core\LTIX;
use \Tsugi\UI\Output;

require "top.php";
require "nav.php";

?>
<div id="container">
<div style="margin-left: 10px; float:right">
<img src="images/Chuck_16x9_SakaiCar_PG4E_flat_1280.png" onclick='window.location.href="https://www.sakaiger.com/sakaicar";' target="_blank" style="padding: 5px; width:400px;">
</div>
<h1>PostgreSQL for Everybody</h1>
<p>
This web site is building a set of free / OER materials to help students
learn the PostgreSQL database.  The site is 
<b>under construction</b> but you are welcome to make use of it as
it is being built.
</p>
<p>
At this point we are working on a way to provide users with a free PostgreSQL server so we can open
this up beyond our enrolled students at the 
<a href="https://www.si.umich.edu/" target="_blank">
University of Michigan School of Information
</a>.
</p>
<h2>Technology</h2>
<p>
This site uses <a href="http://www.tsugi.org" target="_blank">Tsugi</a> 
framework to embed a learning 
management system into this site and handle the autograders.  
If you are interested in collaborating
to build these kinds of sites for yourself, please see the 
<a href="http://www.tsugi.org" target="_blank">tsugi.org</a> website.
<h3>Copyright</h3>
<p>
The material produced specifically for this site is by Charles Severance and others
and is Copyright Creative Commons Attribution 3.0 
unless otherwise indicated.  
</p>
<!--
<?php
echo("IP Address: ".Net::getIP()."\n");
echo(Output::safe_var_dump($_SESSION));
var_dump($USER);
?>
-->
</div>
<?php 
require "foot.php";
