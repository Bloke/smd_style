<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'smd_style';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.3.0';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'https://stefdawson.com/';
$plugin['description'] = 'Stylesheet switcher';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no Ajax)
// 4 = admin+ajax          : only on the admin side (Ajax supported)
// 5 = public+admin+ajax   : on both the public and admin side (Ajax supported)
$plugin['type'] = '0';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '0';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

/** Uncomment me, if you need a textpack
$plugin['textpack'] = <<< EOT
#@admin
#@language en-gb
abc_sample_string => Sample String
abc_one_more => One more
#@language de-de
abc_sample_string => Beispieltext
abc_one_more => Noch einer
EOT;
**/
// End of textpack

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/**
 * smd_style
 *
 * A Textpattern CMS plugin for switching stylesheets on websites
 *  -> Use the browser's switcher or the plugin's built-in jQuery switcher
 *  -> Cookie stores current style in use so it applies across pages
 *  -> Compatible with Txp sheets, file sheets, and rvm_css
 *  -> Ability to put Txp tags in your stylesheet (if using Txp's sheets)
 *
 * @author Stef Dawson
 * @link   https://stefdawson.com/
 */

// Shamelessly plagiarised from ako_cssParse (thanks!)
if (@txpinterface==="css" && gps('smd_styleparse') == 1) {
	global $prefs;
	$sheet = (gps('n'));
	if ($sheet) {
		$css = safe_field('css','txp_css',"name='".doSlash($sheet)."'");
		if ($css) {
			$css = parse($css);
			$css = ($prefs['allow_page_php_scripting']) ? evalString($css) : $css;
			header('Content-type: text/css');
			echo($css);
			exit();
		}
	}
}

// ------------------------
function smd_style($atts, $thing) {
	global $pretext, $thisarticle, $prefs, $variable;

	// Plugin options
	extract(lAtts(array(
		'sheets' => '',
		'form' => '',
		'use_default' => '0', // 0=no; 1=yes as primary sheet
		'promote_recent' => '1', // 0=retain order; 1=promote used sheets but honour core sheets; 2=promote at all costs
		'skip' => 'auto',
		'skip_title' => 'auto',
		'show_empty' => '0',
		'real_sheets' => '0', // 0=no; 1=yes
		'sheets_dir' => @$prefs['rvm_css_dir'],
		'parse_tags' => '0',
		'delim' => ',',
		'paramdelim' => ':',
		'debug' => '0',
	), $atts));

	$out = array();
	$styles = do_list($sheets, $delim);
	$expstyles = array();
	foreach ($styles as $idx => $opt) {
		if (strpos($opt, "?") === 0) {
			$opt = substr(strtolower($opt), 1);
			$tmpa = array();
			if (isset($pretext[$opt]) && $pretext[$opt] != "") {
				$tmpa = do_list($pretext[$opt], $delim);
				$expstyles = array_merge($expstyles, $tmpa);
			} else if (isset($thisarticle[$opt]) && $thisarticle[$opt] != "") {
				$tmpa = do_list($thisarticle[$opt], $delim);
				$expstyles = array_merge($expstyles, $tmpa);
			} else if (isset($variable[$opt]) && $variable[$opt] != "") {
				$tmpa = do_list($variable[$opt], $delim);
				$expstyles = array_merge($expstyles, $tmpa);
			} else if (isset($_GET[$opt]) && $_GET[$opt] != "") {
				$tmpa = do_list(gps($opt), $delim);
				$expstyles = array_merge($expstyles, $tmpa);
			} else if ($show_empty) {
				$expstyles[] = $opt;
			}
		} else {
			$expstyles[] = $opt;
		}
	}
	$styles = $expstyles;

	$skip = ($skip=="auto") ? $use_default : $skip;
	$skip_title = ($skip_title=="auto") ? $use_default : $skip_title;
	$skip_title = ($skip==0) ? 0 : $skip_title;
	if ($use_default > 0) {
		$default = safe_row('css', 'txp_section', "name = '".doSlash($pretext['s'])."'", $debug);
		array_unshift($styles, $default['css']);
	}

	// If a previous style has been chosen, move it to the front of the list (if requested).
	// This forces good browsers to 'flash' less when changing pages
	$cookiestyle = cs("smd_style");
	$cookiestyle = do_list($cookiestyle);
	$cstyles = array();
	foreach ($cookiestyle as $cstyle) {
		$cparts = explode(":", $cstyle);
		if (count($cparts) >= 2) {
			$cstyles[$cparts[0]] = $cparts[1];
      } else {
			$cstyles["all"] = $cparts[0];
		}
	}

	if ($promote_recent && $cstyles) {
		$rejig = array();
		foreach ($styles as $idx => $style) {
			if (($idx < $skip) && $promote_recent == 1) {
				$rejig[] = $style;
				continue;
			}
			if (in_array($style, $cstyles) !== false) {
				$rejig[] = $style;
				break;
			}
		}
		$styles = array_merge($rejig, $styles);
		$styles = array_unique($styles);
	}

	if ($debug) {
		echo "++ STYLES ++";
		dmp($styles);
	}
	$stylecnt = 1;
	$num_styles = count($styles);
	foreach ($styles as $style) {
		$styleopts = do_list($style, $paramdelim);
		$name = (count($styleopts) >= 1) ? $styleopts[0] : '';
		$title = ($stylecnt > $skip || $stylecnt > $skip_title) ? ((count($styleopts) >= 2) ? $styleopts[1] : $name) : '';
		$media = (count($styleopts) >= 3) ? $styleopts[2] : 'screen';
		$rel = (($stylecnt > $skip) ? "alternate " : '') . "stylesheet";
		$url = hu. (($real_sheets && $sheets_dir) ? $sheets_dir . '/' .strtolower(sanitizeForUrl($name)). '.css' : 'textpattern/css.php?n='.$name . (($parse_tags) ? a.'smd_styleparse=1' : ''));

		// Make up the replacement array
		$replacements = array(
				'{smd_style_name}' => $name,
				'{smd_style_url}' => $url,
				'{smd_style_media}' => $media,
				'{smd_style_rel}' => $rel,
				'{smd_style_title}' => $title,
				'{smd_style_counter}' => $stylecnt,
				'{smd_style_total}' => $num_styles,
		);

		if ($debug) {
			echo "++ REPLACEMENTS ++";
			dmp($replacements);
		}

		$out[] = ($thing) ? parse(strtr($thing, $replacements)) : (
					($form) ? strtr(parse_form($form), $replacements) : (
					($real_sheets || $parse_tags) ? '<link rel="'.$rel.'"'. (($title) ? ' title="'.$title.'"' : '') .' href="'.$url.'" media="'.$media.'" type="text/css" />' :
					css(array(
						"format" => "link",
						"media" => $media,
						"name" => $name,
						"rel" => $rel,
						"title" => $title,
						))
					));

		$stylecnt++;
	}

	return join("\n", $out);
}

// ------------------------
// Javascript style switcher
function smd_styleswitch($atts, $thing) {
	global $smd_style_loader;

	// Plugin options
	extract(lAtts(array(
		'wraptag' => 'ul',
		'wrapadd' => '', // Perhaps this should be automatic only
		'class' => 'smd_switcher',
		'html_id' => '',
		'break' => 'li',
		'break_is_tag' => '1',
		'selectlabel' => '',
		'linkclass' => 'smd_styleswitch',
		'activeclass' => 'smd_currstyle',
		'alt_only' => '0', // 0=No; 1=Yes, only show alternate stylesheets
		'form' => '',
		'sort' => '1', // 0=No; 1=Yes
		'case_sensitive' => '0',
		'expiry' => '30', // In days
		'byclass' => '',
		'destination' => 'body',
		'linkloc' => '',
		'clean' => '1',
		'delim' => ',',
		'paramdelim' => ':',
		'debug' => '0',
	), $atts));

	// Set default form info if none specified
	$fixlist = ($byclass) ? 1 : 0;
	$linkloc = ($linkloc) ? $linkloc : (($fixlist) ? 'name' : 'rel');
	$thing = ($form) ? fetch_form($form) : $thing ;
	$thing = (empty($thing))
		? (($fixlist)
			? (($wraptag=='select') ? '<option value="{smd_style_name}" class="{smd_style_linkclass}">{smd_style_title}</option>' : '<a name="{smd_style_name}" href="#" class="{smd_style_linkclass} {smd_style_activeclass}">{smd_style_title}</a>')
			: '<a rel="{smd_style_title}" href="{smd_style_url}" class="{smd_style_linkclass} {smd_style_activeclass}">{smd_style_title}</a>'
		)
		: $thing;
	$thing = str_replace("\r\n", "", $thing);
	if ($wraptag == 'select') {
		$break = (strpos($thing, '</option>')) ? '' : 'option';
		$break_is_tag = 1;
	}

	// Set up the js if using a fixed list of classes
	$byclass = do_list($byclass, $delim);
	$stylecnt = 1;
	$num_styles = count($byclass);
	$classlist = $oplist = array();

	if ($wraptag=='select' && empty($wrapadd)) {
		$wrapadd = <<<EOJS
jQuery(selector).change(function() {
	self.smd_switchStylesheet(self.jqsel, jQuery(selector+" option:selected").val(), type, self.classlist, self.destination);
});
EOJS;
	}

	// Make sure there are always jQuery elements to swing off, thus avoiding js errors
	$class = (trim($class)=="") ? "smd_switcher" : $class;
	$html_id = trim($html_id);
	$linkclass = (trim($linkclass)=="") ? 'smd_styleswitch' : $linkclass;
	$jqSel = ($html_id) ? '#'.$html_id : '.'.$linkclass;
	$stylematch = (($alt_only) ? 'alternate ' : '').'style';
	$break_is_tag = ($break=="") ? 0 : $break_is_tag;

	$cookiestyle = cs("smd_style");
	$cookiestyle = do_list($cookiestyle);
	$cstyles = array();
	foreach ($cookiestyle as $cstyle) {
		$cparts = explode(":", $cstyle);
		if (count($cparts) >= 2) {
			$cstyles[$cparts[0]] = $cparts[1];
      } else {
			$cstyles["all"] = $cparts[0];
		}
	}

	foreach ($byclass as $cls) {
		if ($cls=="") continue;
		$clsopts = do_list($cls, $paramdelim);
		$name = (count($clsopts) >= 1) ? sanitizeForUrl($clsopts[0]) : '';
		$title = (count($clsopts) >= 2) ? $clsopts[1] : $name;
		$media = (count($clsopts) >= 3) ? $clsopts[2] : 'screen';

		if (isset($cstyles[$jqSel]) && $cstyles[$jqSel] == $name) {
			$actCls = $activeclass;
		} else {
			$actCls = '';
		}
		// Make up the replacement array
		$replacements = array(
				'{smd_style_name}' => $name,
				'{smd_style_title}' => addslashes($title),
				'{smd_style_linkclass}' => $linkclass,
				'{smd_style_activeclass}' => $actCls,
				'{smd_style_autosub}' => '', // TODO: set up a call to smd_atyleswitch() that can be called from onchange/onclick/etc
				'{smd_style_counter}' => $stylecnt,
				'{smd_style_total}' => $num_styles,
		);
		$stylecnt++;
		$classlist[] = 'smd_style_cls.addCls("'.$name.'");';
		$oplist[] = "smd_style_cls.addSheet('".parse(strtr($thing, $replacements))."');";
	}
	$classlist = join(n, $classlist);
	$oplist = join(n, $oplist);

	$pub_path = preg_replace('|//$|','/', rhu.'/');

	$out = array();
	if ($wraptag) {
		$out[] = '<'.$wraptag.(($html_id) ? ' id="'.$html_id.'"' : '').' class="'.$class.'"></'.$wraptag.'>';
	}

	$out[] = <<<EOJS
<script type="text/javascript">
<!--
jQuery(function() {
smd_style_cls = new smd_ClassHandler("{$fixlist}");
{$classlist}
{$oplist}
smd_style_cls.setBreak('{$break}');
smd_style_cls.setBreakIsTag('{$break_is_tag}');
smd_style_cls.setHtmlID('{$html_id}');
smd_style_cls.setClassName('{$class}');
smd_style_cls.setJQSel('{$jqSel}');
smd_style_cls.setDestination('{$destination}');
smd_style_cls.setLinkClass('{$linkclass}');
smd_style_cls.setLinkLoc('{$linkloc}');
smd_style_cls.setClean('{$clean}');
smd_style_cls.setPath('{$pub_path}');
smd_style_cls.setActiveClass('{$activeclass}');
smd_style_cls.setSort('{$sort}');
smd_style_cls.setCaseSensitive('{$case_sensitive}');
smd_style_cls.setExpiry('{$expiry}');
smd_style_cls.setStyleMatch('{$stylematch}');
smd_style_cls.setThing('{$thing}');
smd_style_cls.construct();
});
-->
</script>
EOJS;

	if ($smd_style_loader == 0) {
		$out[] = <<<EOJS
<script type="text/javascript" src="/textpattern/textpattern.js"></script>
<script type="text/javascript">
<!--
var smd_style_cls = '';
function smd_ClassHandler(type) {
	// Variables
	this.classlist = [];
	this.stylelist = [];
	this.act_class = '';
	this.brk = '';
	this.brkistag = '';
	this.htmlid = '';
	this.classname = '';
	this.jqsel = '';
	this.destination = '';
	this.linkclass = '';
	this.linkloc = '';
	this.clean = '';
	this.path = '.';
	this.activeclass = '';
	this.wraptag = '';
	this.sortit = '';
	this.case_sensitive = '';
	this.expiry = '';
	this.stylematch = '';
	this.thing = '';
	var self = this;

	// Methods
	this.addCls = function addCls(val) { this.classlist.push(val); }
	this.addSheet = function addSheet(val) { this.stylelist.push(val); }
	this.setBreak = function setBreak(val) { this.brk = val; }
	this.setBreakIsTag = function setBreakIsTag(val) { this.brkistag = val; }
	this.setHtmlID = function setHtmlID(val) { this.htmlid = val; }
	this.setClassName = function setClassName(val) { this.classname = val; }
	this.setJQSel = function setJQSel(val) { this.jqsel = val; }
	this.setLinkClass = function setLinkClass(val) { this.linkclass = val; }
	this.setLinkLoc = function setLinkLoc(val) { this.linkloc = val; }
	this.setClean = function setClean(val) { this.clean = val; }
	this.setPath = function setPath(val) { this.path = val; }
	this.setActiveClass = function setActiveClass(val) { this.activeclass = val; }
	this.setDestination = function setDestination(val) { this.destination = val; }
	this.setSort = function setSort(val) { this.sortit = val; }
	this.setCaseSensitive = function setCaseSensitive(val) { this.case_sensitive = val; }
	this.setExpiry = function setExpiry(val) { this.expiry = val; }
	this.setStyleMatch = function setStyleMatch(val) { this.stylematch = val; }
	this.setThing = function setThing(val) { this.thing = val; }
	this.getClasses = function getClasses() { return this.classlist; }

	this.sorter = function sorter() {
		if (this.sortit==1) {
			this.stylelist = (this.case_sensitive==0)
				? this.stylelist.sort(function(a,b) {
					var x = a.toLowerCase();
					var y = b.toLowerCase();
					return ((x < y) ? -1 : ((x > y) ? 1 : 0));
					})
				: this.stylelist.sort();
		}
	}
	// Switch to the given stylesheet
	this.smd_switchStylesheet = function smd_switchStylesheet(lcls, styleref, type, clslist, dest) {
		if (lcls == self.jqsel) {
			if (type == 1) {
				jQuery.each(clslist, function(idx, cls) {
					if (jQuery(dest).hasClass(cls)) {
						jQuery(dest).removeClass(cls);
					}
				});
				if (styleref != "default") {
					jQuery(dest).addClass(styleref);
				}
			} else {
				jQuery('link[rel*=style][title]').each(function(id) {
					this.disabled = true;
					if (this.getAttribute('title') == styleref) this.disabled = false;
				});
			}
			if (this.expiry > 0) {
				var cstyle = getCookie('smd_style');
				if (cstyle == null) {
					cstyle = '';
				}
				cstyle = cstyle.split('^');
				var newstyle = self.jqsel+':'+styleref;
				var found = false;

				for (var idx = 0; idx < cstyle.length; idx++) {
					if (cstyle[idx] == '') continue;
					var thispair = cstyle[idx].split(':');
					if (thispair[0] == self.jqsel) {
						cstyle[idx] = newstyle;
						found=true;
						break;
					}
				}
				if (found == false) {
					cstyle.push(newstyle);
				}

				var date = new Date();
				date.setTime(date.getTime() + (self.expiry*24*60*60*1000));
				var expires = '; expires=' + date.toGMTString();
				document.cookie = 'smd_style=' + cstyle.join('^') + expires + '; path='+self.path;
			}
		}
	}

	// Adapted from https://phpjs.org/functions/strtr:4965ce2e-29d4-48da-91f9-07ca86a786ee
	this.smd_strtr = function smd_strtr(str, from, to) {
		var idx = 0, lgth = 0;
		if (typeof from === 'object') {
			for (fr in from) {
				re = new RegExp(fr, 'g');
				str = str.replace(re, from[fr]);
			}
			return str;
		}

		lgth = to.length;
		if (from.length < to.length) {
			lgth = from.length;
		}
		for (idx = 0; idx < lgth; idx++) {
			str = str.replace(from[idx], to[idx]);
		}
		return str;
	}

	this.autosub = function autosub(id) {
		self.smd_switchStylesheet(self.jqsel, jQuery(this(id)).getAttribute(self.linkloc), type, self.classlist, self.destination);
	}
	// Constructor from here
	this.construct = function construct() {
		if (type == "0") {
			// Generate the list of stylesheets
			jQuery('link[rel*='+self.stylematch+'][title]').each(function(id) {
				if (this.disabled == true) {
					act_class = '';
				} else {
					act_class = " "+self.activeclass;
				}
				var reps = new Array();
				reps = {
					"{smd_style_title}" : this.getAttribute('title'),
					"{smd_style_url}" : this.getAttribute('href'),
					"{smd_style_linkclass}" : self.linkclass,
					"{smd_style_activeclass}" : act_class
				};

				self.addSheet(self.smd_strtr(self.thing, reps));
			});
		}

		self.sorter();

		var selector = (self.htmlid=="") ? "."+self.classname : "#"+self.htmlid;
		{$wrapadd}
		if (self.clean==1) {
			jQuery(selector).empty();
		}
		jQuery(selector).append(
			((self.brkistag==0)
				? self.stylelist.join(self.brk)
				: "<"+self.brk+">"+self.stylelist.join("</"+self.brk+"><"+self.brk+">")+"</"+self.brk+">"
			)
		);

		jQuery('.'+self.linkclass).click(function() {
			jQuery('.'+self.linkclass).each(function() {
				jQuery(this).removeClass(self.activeclass);
			});
			self.smd_switchStylesheet(self.jqsel, this.getAttribute(self.linkloc), type, self.classlist, self.destination);
			jQuery(this).addClass(self.activeclass);
			return false;
		});
		if (this.expiry > 0) {
			var currStyle = getCookie('smd_style');
			if (currStyle) {
				currStyle = currStyle.split('^');
				for (var idx = 0; idx < currStyle.length; idx++) {
					cstyle = currStyle[idx].split(':');
					self.smd_switchStylesheet(cstyle[0], cstyle[1], type, self.getClasses(), self.destination);
				}
			}
		}
	}
}
-->
</script>
EOJS;
		$smd_style_loader++;
	}
	return join("\n", $out);
}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. smd_style

Manage alternate stylesheets and (optionally) switch between them via Javascript. Alternatively, switch between CSS class names on arbitrary objects to effect style changes.

Features:

* Provide a list of style sheet names derived from:
** a given list
** any article field
** a @<txp:variable />@
** a URL variable
** some combination of the above
* Use either the built-in stylesheet switching ability of modern browsers or the plugin's javascript switchers
* Current style stored in a cookie: can be instructed to load that style first on subsequent pages
* Compatible with Txp stylesheets, 'real' stylesheets and the rvm_css plugin
* Optionally build your own stylesheet syntax via a form/container
* Put Txp tags inside your stylesheets if you wish (requires Txp-style sheet processing, i.e real_sheets must be off)

h2(install). Installation / Uninstallation

Download the plugin from either "GitHub":https://github.com/bloke/smd_style, or the "software page":http://stefdawson.com/smd_style, paste the code into the Txp Admin -> Plugins pane, install and enable the plugin. Visit the "forum thread":http://forum.textpattern.com/viewtopic.php?id=28592 for more info and to report the success (or otherwise) of this plugin.

To uninstall, simply delete from the Admin -> Plugins page.

h2(usage). Usage

There are two tags available: one for use in the @<head>@ of your page to set up the alternate styles, and the other adds a javascript stylesheet switcher to the page, wherever you want it to appear.

h2(#smd_style). smd_style

p(tag-summary). Somewhere in your HTML @<head>@ tag, add a call to @smd_style@ to include a list of alternate stylesheets. Use the following attributes to customise the output:

h3. Attributes

* %sheets% : a comma-separated list of stylesheets to include. These are their Txp names as given on the Presentation->Styles tab. See "sheet names":#sheetnames for more
* %form% : the Txp form in which to build your own @<link>@ tags. See "replacement tags":#replacements. If not specified (and no container is used) a standard HTML link will be output
* %use_default% : the list of stylesheets given in the @sheets@ parameter does not usually contain the 'default' stylesheet associated with the section. This allows you to always include a 'global' sheet with core rules in it and have the alternates override/fill in the gaps. If, however, you wish smd_style to output the default stylesheet so you don't have to do it elsewhere, specify @use_default="1"@
* %promote_recent% : Determine what to do when the page loads and the visitor has already switched to an alternate stylesheet. This can be useful to try and help prevent 'flashes of unstyled content' in good browsers. Can take one of three values:
** @0@ : do nothing, i.e. sheets are always loaded in the given order
** @1@ (the default) : any style sheet that has been chosen by the visitor will be loaded as soon as possible *after* any core sheets (i.e. sheets that you want to be non-alternate). Thus if you have 2 'core' sheets and the visitor chooses the alternate sheet that used to be #4 in the list, it will now be loaded 3rd
** @2@ : any style sheet that has been chosen by the visitor will be loaded first, i.e. the chosen sheet will _always_ be promoted to the #1 slot
* %skip% : in the list of sheets, skip this number of sheets before starting to label them as 'alternate'. The special default setting of @auto@ behaves thus:
** if @use_default="1"@, the first sheet in the list will be skipped (i.e. will always be a true stylesheet). Equivalent to @skip="1"@
** if @use_default="0"@ the first sheet in the list will be an alternate stylesheet (i.e. equivalent to @skip="0"@)
* %skip_titles% : in the list of sheets, skip this number of sheets before starting to add @title@ attributes. The special default setting of @auto@ works the same as for the @skip@ attribute. Note that some combinations of skip/skip_title are not permitted by the HTML specification so are disallowed. See "skipping":#skipping for more details
* %show_empty% : by default, if any field (e.g. a custom field) has no value assigned to it and you try to read a sheet name, the field will be ignored. Setting this to @1@ will include a stylesheet with the name of the field itself. See "Example 5":#eg5 for a practical application of this
* %real_sheets% : normally, standard @css.php?n=sheet_name@ references are output. If you prefer 'real' stylesheet filename references, set this attribute to 1. Note you will have to ensure that the stylesheet files really exist on your server in the given directory (see @sheets_dir@)
* %sheets_dir% : location (relative to the root of your textpattern installation) where your stylesheets are to be found if using @real_sheets="1"@. If you have the rvm_css plugin installed it will default to the directory specified in Admin -> Preferences -> Advanced -> Style directory
* %parse_tags% : if you wish to be able to put Txp tags inside your style sheets and have them parsed, set this attribute to 1. Note that this only works if @real_styles="0"@  because there's no way for Textpattern to intercept a direct call to a stylesheet. Using this feature also incurs a (small) time penalty as each sheet is parsed
* %delim% : the delimiter to use between @sheets@. Default is the comma (,)
* %paramdelim% : the delimiter to use between additional sheet information. Default is the colon (:)

h3(#sheetnames). Sheet names

When using the @sheets@ attribute, if you precede any sheet name with a @?@ the Txp environment will be searched for a matching location. For example, @sheets="core, ?custom3"@ would load core.css and then look in the custom3 field for a further list of stylesheets to load. The order in which the locations are searched is: 1) Article fields; 2) @<txp:variable />@; 3) The URL. If a @?@ value is supplied and a suitable location cannot be found, the value you gave will be used verbatim.

You may also specify up to two additional pieces of information in each sheet definition: 1) the sheet's "pretty name" that people will see, and 2) its media type (usually @screen@). To do this, separate the values with colons (unless changed via the @paramdelim@ attribute) like this:

bc. <txp:smd_style use_default="1"
     sheets="core, cats:Cats of the world, printer:Print layout:print" />

That would output something similar to the following:

bc. <link rel="stylesheet" type="text/css" media="screen"
     title="default" href="http://site.com/textpattern/css.php?n=default" />
<link rel="alternate stylesheet" type="text/css" media="screen"
     title="core" href="http://site.com/textpattern/css.php?n=core" />
<link rel="alternate stylesheet" type="text/css" media="screen"
     title="Cats of the world" href="http://site.com/textpattern/css.php?n=cats" />
<link rel="alternate stylesheet" type="text/css" media="print"
     title="Print layout" href="http://site.com/textpattern/css.php?n=printer" />

h3(#replacements). Replacement tags

If you don't like the standard output or wish to fashion your own @<link>@ tags then you can specify container content or a @form@ with which to process the results. There are replacement variables you can employ within your layout to insert the relevant content for each stylesheet:

* @{smd_style_name}@ : the stylesheet name as given in the Txp Styles tab
* @{smd_style_url}@ : the complete URL (Txp-style or 'real' URL depending on the @real_sheets@ attribute)
* @{smd_style_media}@ : the media type of stylesheet (e.g. screen, print...)
* @{smd_style_rel}@ : the stylesheet relationship (either 'stylesheet' or 'alternate stylesheet')
* @{smd_style_title}@ : the human-friendly title you have assigned to the stylesheet. If not specified, it defaults to the stylesheet name
* @{smd_style_counter}@ : the current stylesheet number being processed
* @{smd_style_total}@ : the total number of stylesheets in the list

h3(#skipping). Skipping

The HTML specification allows for three types of stylesheet:

* Persistent : ones that are forced on the user unless they choose "No Styles" from their browser menu
* Preferred : ones that are always loaded but can be swapped out for others
* Alternate : ones that are not loaded by default but can be switched in and out freely by the user

With a combination of @use_default@, @skip@ and @skip_title@ you can offer any of these types. Some combinations of @skip@ and @skip_title@ are not permitted because they would render illegal HTML (e.g. the case when you have an alternate stylesheet without a title).

To try and make it a little easier to visualise, here is a table that shows which of the stylesheet flavours are served with varying values of these attributes. Assume you have two stylesheets labelled A and B. Sheet A will be set to:

|. &nbsp; |. *skip=0* |. *skip=1* |
| *skip_title=0* | Alternate | Preferred |
| *skip_title=1* | (disallowed) Alternate | Persistent |

The same logic applies if using the @auto@ parameter and/or the @use_default@ attribute. The first sheet in the list will become one of those flavours of Stylesheet where the values of @skip@ and @skip_title@ intersect. If using higher values, the first few sheets become those particular flavours.

h2(#smd_styleswitch). smd_styleswitch

p(important). Requires jQuery to be loaded on your page, before the call to smd_styleswitch. Tested with v1.7.

On its own the "smd_style":#smd_style tag gives the visitor the ability to switch styles via the 'View alternate stylesheet' facility of modern browsers. If you wish to offer them a way of instantaneously switching (and remembering) the chosen theme from within your content, use @smd_styleswitch@ somewhere in the flow of your page to insert a stylesheet switcher.

Alternatively, you may decide not to bother with traditional stylesheet switching at all and may prefer to simply "switch class names":#mode2 on certain elements of the page to effect changes.

It can be customised as follows:

h3(#mode1). Mode 1: Stylesheet switcher

h4(#m1attributes). Attributes

* %wraptag% : standard HTML tag to wrap the entire switcher with. Default is @ul@. If you do not specify this attribute you _must_ create your own container somewhere else in the page and tell the plugin its ID or class so it can find it
* %html_id% : HTML ID to apply to the wraptag. Default is unset. If you have not specified a @wraptag@, you may give the ID of your own container so the switcher can be inserted
* %class% : CSS class to apply to the wraptag. Default is @smd_switcher@. If you have not specified a @wraptag@, you may give the class name of your own container so the switcher can be inserted
* %break% : Default is @li@. Can be either:
** a standard HTML tag to wrap each stylesheet name and link with.
** text to place between each element, such as @break="--"@ (see @break_is_tag@)
* %break_is_tag% : if the @break@ attribute is a tag, this should be set to 1 (which it is by default). If you wish to use something else between each stylesheet name (e.g. @break=" | "@) set this to 0
* %linkclass% : CSS class to apply to each stylesheet link that is inserted. Default is @smd_styleswitch@
* %linkloc% : the HTML attribute to compare for a match. When you click a link, in order to determine which one has been clicked, some unique information must be present in each link. You might choose the anchor's 'name' attribute (in which case, use @linkloc="name"@) or perhaps the 'rel'. Default: @rel@ (in this mode) or @name@ in class switching mode
* %activeclass% : CSS class to indicate the currently selected stylesheet link. Default is @smd_currstyle@
* %alt_only% : if set to @1@ will only offer stylesheets with a rel that includes 'alternate' in the list. The default behaviour (0) is to list all stylesheets on the page that contain a 'title' attribute
* %sort% : set to 1 (the default) to sort the stylesheets in alphabetical order. If set to 0, the most recently used stylesheet will be shown at the head of the list
* %case_sensitive% : whether the @sort@ is case sensitive (1) or not (0). Default is 0
* %expiry% : number of days after which the @smd_style@ cookie that holds the current style information remains valid. Default is @30@. Set to 0 to disable cookie storing
* %form% : if you prefer to make your own links instead of using the default anchor tags, either use the tag as a container or specify a Txp form with which to process each link. Default: unset

The stylesheet switcher that is inserted at the given location consists of an anchor tag with a link to the new stylesheet. The text within the anchor is the stylesheet title (if supplied) or its name if not. Note that the switcher will pick up _all stylesheets that have a title attribute_ so if any of your other stylesheets not controlled by the plugin have titles, they will be included as well. This could be considered a feature(!)

If you are a neatness buff and prefer that all javascript goes in the @<head>@, using @wraptag=""@ will allow you to insert the @<txp:smd_styleswitch />@ tag in the head of your document while locating your switching container elsewhere. Just tell the plugin either the @html_id@ or @class@ of your switch container and the switcher will be inserted there.

h3(#mode2). Mode 2: Class switcher

The other way to use the tag is as a class changer. You may target any selector on the page and offer a list of class names that will be applied to that element when the relevant switch link is chosen.

h4(atts #m2attributes). Attributes

Takes the same attributes as above, with the exception that @alt_only@ is ignored. In addition:

* %byclass% : this puts the plugin into "class switching mode". It works like the @sheets@ attribute of the smd_style tag. Specify a list of class names that users may choose between. If you follow the class name with a colon (:) you may specify a "friendly" name to show to people instead of the class name itself. The special class name @default@ removes all classes that have been applied to the selector. See "example 7":#eg7.
* %destination% : the DOM element ID you wish to add classes to, e.g. @destination="#my_div"@ or @destination=".my_class"@. Default: @body@
* %clean% : if this is set to 1, anything inside your designated wraptag container will be removed before adding the switcher content. Default: 1
* %delim% : the delimiter to use between items in the @byclass@ attribute. Default: comma
* %paramdelim% : the delimiter to use between class and name in the @byclass@ attribute. Default: colon

If you put more than one smd_styleswitch tag in this mode, each will function independently so you can add class combos to the same element (see "example 8":#eg8). Very useful for specifying two different lists of classes: one for "screen" styles and one for "print styles" that operate independently.

But beware the following caveats when putting multiple smd_styleswitch tags on a single page. For hassle-free operation, you should:

* attach each tag to a unique element. In other words, change the @class@ or @html_id@ to something different in each tag
* ensure that @linkclass@ is also unique for each tag, otherwise you may get odd results when choosing a style
* be aware that using the same classname twice (e.g. @default@ in two tags) will not switch properly if you are using a @linkloc="id"@ because the plugin will try and assign the same name to two different IDs, which is illegal DOM markup

h2(#gotchas). Gotchas

* Make sure jQuery can be found by the page, or smd_styleswitch will do nothing
* If using certain wraptag elements (e.g. the default "ul"), the HTML validator may complain that the element is "unfinished" because it does not render the javascript which inserts the remaining 'li' tags. If this bothers you, set @wraptag=""@ and specify your own container, filling it with an empty child tag (e.g. @<li>&nbsp;</li>@). The plugin will empty the contents of the container before adding the links as long as the @clean="1"@ is used in the tag (which it does by default)
* You must use one (or both) of either @class@ or @html_id@ attributes, otherwise the plugin will not be able to attach itself. the html_id is used in preference to @class@ if both are used

h2. Examples

h3(#eg1). Example 1: alternate stylesheets

bc. <txp:smd_style
     sheets="yellow, green, brown, blue, pink, black" />
<txp:smd_styleswitch wraptag="" />

Somewhere further down the page you would have to add:

bc. <ul class="smd_switcher"></ul>

That will add six alternate stylesheets to the page and add a switcher wherever your @<ul>@ appears on the page. A few modifications are possible:

* with rvm_css installed, adding @real_sheets="1"@ to the @smd_style@ tag would change the URLs to 'real' CSS file paths
* adding @use_default="1"@ will add the current section's stylesheet to the list as well. If it happens to be the same as one of the ones in the list already, it will only be used once
* adding @skip="2"@ will cause the 'yellow' and 'green' sheets to be static (non-alternate)
* using @promote_recent@ will change the load order as follows:
** @0@ : load order will always be yellow, green, brown, blue, pink, black
** @1@ : promote the chosen sheet up the list. With @use_default="1" skip="2"@, if the visitor chose 'pink' as their theme and refreshed the page, the load order would be: yellow, pink, green, brown, blue, black (yellow and pink would be listed as "static" stylesheets)
** @2@ : force whichever sheet was most recently chosen to be the first loaded. Again, if the visitor chose 'pink' as their preferred sheet and refreshed the page, the load order would be pink, yellow, green, brown, blue, black (pink and yellow would be static sheets)

h3(#eg2). Example 2: using txp_variable

To set up the names of the stylesheets you want to include in a @<txp:variable />@ try this:

bc. <txp:variable name="alt_styles"
     value="girls:Girly theme, boys:Lads only" />
<txp:smd_style sheets="?alt_styles" />

h3(#eg3). Example 3: persistent sheets

Grab the default sheet, the "fixed" sheet (and make them both 'static') then look in the custom field labelled @alt_styles@ and the URL variable @mytheme@ for more sheet definitions.

bc. <txp:smd_style sheets="fixed, ?alt_styles, ?mytheme"
     use_default="1" skip="2" />

Note that no distinction is made where to find the variables, they are just checked in order and the first one it finds that matches (if at all) will be used. Thus if you had a custom field labelled @alt_styles@ and someone put @site.com/my_page?alt_styles=two,three,four@ on the URL, you would still have them read from the custom field because it is 'higher' in the hierarchy. One caveat is that if the custom field is empty, the next place in the list is checked until all locations are exhausted, though you can ignore empty fields with @show_empty="0"@.

h3(#eg4). Example 4: custom link layout

For a non-HTML DTD you may need to drop the trailing @/@ on the @<link>@ elements, so you could do this:

bc. <txp:smd_style sheets="blue, red, green">
 <link rel="{smd_style_rel}" type="text/css"
     media="{smd_style_media}" href="{smd_style_url}"
     title="{smd_style_title}">
</txp:smd_style>

h3(#eg5). Example 5: show_empty

The @show_empty@ attribute seems fairly pointless on the surface, but imagine this scenario: you set up a custom field called @default_style@. When authoring articles, people can insert the name of a stylesheet in there to style the page with. But if they leave it blank you could ensure the page renders with at least _some_ default content by creating a stylesheet called "default_style" (i.e. the same name as the custom field) and using a tag like this:

bc. <txp:smd_style sheets="?default_style"
     show_empty="1" />

If the custom field is empty, the plugin converts the request to @<txp:smd_style sheets="default_style" />@.

h3(#eg6). Example 6: tag parsing

bc. <txp:smd_style sheets="first, second, third"
     parse_tags="1" />

If one of your stylesheets contained the following:

bc. .filler {
   background:url(<txp:site_url />images/bg.jpg);
   color:red;
}

The Txp tag would be replaced with the contents before being served. This does have a performance penalty as the stylesheet is fetched and parsed, but can be very useful. You may use @<txp:php></txp:php>@ tags in the stylesheet as long as the admin preference to allow page level PHP is enabled. Many thanks to akokskis for some of the code from ako_cssParse that enables this feature.

h3(#eg7). Example 7: class switching

bc. <txp:smd_styleswitch
     byclass="default:Normal, mini:Small,
       maxi:Large" class="switcher" />

That tag will show a three-way class switcher containing three anchors as an unordered list. When you click:

* _Small_ the @mini@ class is added to the @<body>@ tag
* _Large_ the @maxi@ class replaces the @mini@ class
* _Normal_ either @mini@ or @maxi@ are removed (whichever was in force at the time)

Thus with appropriate rules in a stylesheet you can switch the font size of the document on the fly. For example:

bc. body {
  font-size:1em;
}
body.mini {
  font-size:.8em;
}
body.maxi {
  font-size:1.3em;
}

If you loaded your stylesheet with @media="print"@ then the styles would only be applied to the printed version of the page.

h3(#eg8). Example 8: multiple class switchers

Let's add a second smd_styleswitch tag to the page, in addition to the one in "example 7":#eg7.

bc. <txp:smd_styleswitch
     byclass="default:Black on white, yob:Yellow on blue,
       gob:Green on black" linkclass="smd_colours" />

Notice first that this one takes @linkclass@. This is so that the two switchers are uniquely addressable -- the first used the default linkclass of @smd_styleswitch@. Without this, the two switchers will clash and things won't work properly.

The second switcher will be rendered and the two work independently; visitors can choose one style from each switcher. So they might click:

* _Large_ and _Yellow on blue_ : both @maxi@ and @yob@ classes would be added to the @<body>@ tag
* _Large_ and _Green on black_ : @maxi@ and @gob@ classes would apply to the body
* _Normal_ and _Green on black_ : just the @gob@ style is applied
* _Normal_ and _Black on white_ : both styles are removed. Note that any other styles you may have manually applied are left untouched

Again, with suitable classes you can allow people to combine the look of a page to their tastes. By default, their preferences are saved in the @smd_style@ cookie so each page will have the same style applied.

h2(author). Author

"Stef Dawson":https://stefdawson.com/contact

# --- END PLUGIN HELP ---
-->
<?php
}
?>