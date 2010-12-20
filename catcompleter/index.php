<?php
/*
    Copyright © 2010 Petr Kadlec <mormegil@centrum.cz>

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.

*/

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once(dirname( __FILE__ ) . '/../includes/db.php');

$catname = isset($_POST['catname']) ? $_POST['catname'] : null;
$project = isset($_POST['project']) ? $_POST['project'] : null;

?><!DOCTYPE HTML>
<html>
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <title>Category completer</title>
  <link rel="stylesheet" href="http://cs.wikipedia.org/skins-1.5/common/main-ltr.css" media="screen" />
  <link rel="stylesheet" href="http://cs.wikipedia.org/skins-1.5/common/shared.css" media="screen" />
</head>
<body class="mediawiki ltr">
    <h1>Category completer</h1>

    <form method="post">
        <table>
            <tr><th><label for="dbname">Home language:</label></th><td><input name="project" id="project" maxlength="20" value="<?php echo $project ? htmlspecialchars($project) : '' ?>" /></td></tr>
            <tr><th><label for="dbname">Category:</label></th><td><input name="catname" id="catname" maxlength="255" value="<?php echo $catname ? htmlspecialchars($catname) : '' ?>" /></td></tr>
            <tr><td colspan="2"><input type="submit" value="Go!" /></td></tr>
        </table>
<?php

function execute($catname, $homelang, $sourcewiki)
{
    $db = connect_to_db($homelang . 'wiki');
    if (!$db)
    {
        echo '<p class="error">Error connecting to database</p>';
        return;
    }

	$remotedb = connect_to_db($sourcewiki . 'wiki');
	if (!$remotedb)
	{
		echo "<p class='error'>Error connecting to $sourcewiki</p>";
		return;
	}

	$catname = title_to_db($catname);
	$caturl = str_replace('&', '%26', $catname);

	echo "<!-- Loading interwiki -->\n";
	flush();
	$query = "SELECT ll_title FROM langlinks INNER JOIN page ON ll_from = page_id WHERE page_title = '" . mysql_real_escape_string($catname, $db) . "' AND page_namespace = 14 AND ll_lang='" . mysql_real_escape_string($sourcewiki, $db) . "'";
    $queryresult = mysql_query($query, $db);
    if (!$queryresult)
    {
        echo '<p class="error">Error executing interwiki query: ' . htmlspecialchars(mysql_error()) . '</p>';
        return;
    }

	$remotecatname = null;
	if ($row = mysql_fetch_assoc($queryresult))
    {
		$title = $row['ll_title'];
		$colon = strpos($title, ':');
		if ($colon === FALSE)
		{
			echo '<p class="error">Suspicious interwiki: ' . htmlspecialchars($sourcewiki . ": " . $title) . '</p>';
			return;
		}
		$remotecatname = substr($title, $colon + 1);
	}
	else
	{
		echo '<p class="error">Interwiki link not found</p>';
		return;
	}
	
	echo "<!-- Loading category -->\n";
	flush();
	$queryresult = mysql_query("SELECT page_title FROM page INNER JOIN categorylinks ON cl_from = page_id WHERE cl_to = '" . mysql_real_escape_string(title_to_db($catname), $db) . "' AND page_namespace = 0 LIMIT 500", $db);
    if (!$queryresult)
    {
        echo '<p class="error">Error executing category query: ' . htmlspecialchars(mysql_error()) . '</p>';
        return;
    }

	$localarticles = array();
    while ($row = mysql_fetch_array($queryresult))
    {
		$localarticles[$row[0]] = 1;
	}

	echo "<!-- Starting interwiki processing -->\n";
	flush();
	$countmissing = 0;
	$remotearticles = 0;
	$homewiki = 'http://' . htmlspecialchars($homelang, ENT_QUOTES) . '.wikipedia.org/wiki/';
	$remotewiki = 'http://' . htmlspecialchars($sourcewiki, ENT_QUOTES) . '.wikipedia.org/wiki/';
	$remotecaturl = $remotewiki . "Category:" . htmlspecialchars(str_replace('?', '%3F', title_to_db($remotecatname)), ENT_QUOTES);

	$query = "SELECT ll_title, page_title FROM categorylinks INNER JOIN langlinks ON cl_from = ll_from AND ll_lang = '" .  mysql_real_escape_string($homelang, $remotedb) .  "' INNER JOIN page ON page_id = ll_from WHERE cl_to = '" .  mysql_real_escape_string(title_to_db($remotecatname), $remotedb) . "' AND page_namespace=0 LIMIT 500";
	$result = mysql_query($query, $remotedb);
	if (!$result)
	{
		echo "<p class='error'>Error processing query at $sourcewiki</p>";
		return;
	}

	echo "<h3><a href='$remotecaturl'>$sourcewiki:Category:" . htmlspecialchars($remotecatname) . "</a></h3>";

	while ($row = mysql_fetch_array($result))
	{
		++$remotearticles;
		$article = $row[0];
		$remotedbarticle = $row[1];
		$dbarticle = title_to_db($article);
		if (!array_key_exists($dbarticle, $localarticles))
		{
			$remotearticle = title_from_db($remotedbarticle);
			$remotearticleurl = str_replace('?', '%3F', $remotedbarticle);
			$articleurl = str_replace('?', '%3F', $dbarticle);

			if ($countmissing == 0)
			{
				echo "<table class='wikitable sortable'>\n";
				echo "<tr><th>Remote</th><th>Local</th><th>HotCat</th></tr>\n";
			}

			echo "\t<tr>\n";
			echo "\t\t<td><a href='$remotewiki" . htmlspecialchars($remotearticleurl, ENT_QUOTES) . "'>" . htmlspecialchars($remotearticle) . "</td>\n";
			echo "\t\t<td>\n";
			echo "\t\t\t<a href='$homewiki" . htmlspecialchars($articleurl, ENT_QUOTES) . "'>" . htmlspecialchars($article) . "</a>\n";
			echo "\t\t</td>\n";
			echo "\t\t<td>\n";
			echo "\t\t\t(<a href='$homewiki" . htmlspecialchars($articleurl, ENT_QUOTES) . "?action=edit&hotcat_comment=%20(CatCompleter%20via%20[[$sourcewiki:Category:$remotecatname]])&amp;hotcat_newcat=" . htmlspecialchars($caturl, ENT_QUOTES) . "'>+</a>)\n";
			echo "\t\t</td>\n";
			echo "\t</tr>\n";
			flush();

			++$countmissing;
		}
	}

	if ($countmissing == 0)
	{
		echo "<p>Nothing to do... " . count($localarticles) . " articles at $homelang, $remotearticles at $sourcewiki</p>";
		return;
	}
	else
	{
		echo "</table>";
	}
}

function sourcewikichoice($catname, $homelang)
{
    $db = connect_to_db($homelang . 'wiki');
    if (!$db)
    {
        echo '<p class="error">Error connecting to database</p>';
        return;
    }

	$catname = title_to_db($catname);
	$caturl = str_replace('&', '%26', $catname);

	$query = "SELECT ll_lang, ll_title FROM langlinks INNER JOIN page ON ll_from = page_id WHERE page_title = '" . mysql_real_escape_string($catname, $db) . "' AND page_namespace = 14";
    $queryresult = mysql_query($query, $db);
    if (!$queryresult)
    {
        echo '<p class="error">Error executing interwiki query: ' . htmlspecialchars(mysql_error()) . '</p>';
        return;
    }

	$first = true;
	while ($row = mysql_fetch_assoc($queryresult))
    {
		$lang = $row['ll_lang'];
		$title = $row['ll_title'];
		$colon = strpos($title, ':');
		if ($first)
		{
			echo '<h2>Choose source language</h2>';
			$first = false;
		}
		if ($colon === FALSE)
		{
			echo "<span class='error'>$lang</span>";
		}
		else
		{
			echo "<input type='submit' name='sourcewiki' value='$lang' />";
		}
		$first = false;
	}

	if ($first)
	{
		echo '<p class="error">No interwiki links found!</p>';
	}
}

if ($catname && $project)
{
	$sourcewiki = isset($_POST['sourcewiki']) ? $_POST['sourcewiki'] : null;
	if ($sourcewiki)
	{
		execute($catname, $project, $sourcewiki);
	}
	else
	{
		sourcewikichoice($catname, $project);
	}
}
?>
    </form>
</body>
</html>