=== Plugin Name ===
Contributors: bastb
Donate link: http://www.tenberge-ict.nl/tools/wordpress/lips/
Tags: LinkedIn, Linked, In, API, OAuth, profile, zzp, freelance, cv, experience, resume, template, Smarty, templating, business, job, project, hiring, professional, stackoverflow, stackexchange, reputation, serverfault, superuser.com, curriculum, curriculum vitae, vitae, education, stackoverflow
Requires at least: 3.3.1
Tested up to: 4.0
Stable tag: 0.8.15
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

This tool downloads your LinkedIn&reg; profile and maintains a selectable page on your WordPress installation.

== Description ==
So, you've got your own WordPress site, and you're freelancing. You're maintaining a LinkedIn&reg; profile because you have to, and you need to display your resume on your own site too. Wouldn't it be cool if you just maintain your resume at LinkedIn&reg; and place a copy of that data on your own site and updating it would require just about pressing a button?
The resume page markup must, of course, match the layout of your site. Look no further, this plugin is all you need. And more.

LiPS creates a local copy of your LinkedIn&reg; Profile, using the LinkedIn&reg; REST API to get the data. There's no page-parsing or screen-scraping, it's just your data, structured in a way it allows for automatic processing using a template.
The REST API uses OAuth, so it does not need to know your LinkedIn&reg; username and password. It uses a token which is granted access to your data. Revoking access is easy too, in fact, it's done automatically.
There's a drawback, and that's the user needing a LinkedIn Developer account.

This version of LiPS does not use the OAuth callback feature, meaning that it can run on localhost.

The tool processes the profile data and creates a page, using the Smarty templating engine. Smarty is included in the distribution, as are two minimal templates. You can choose which page to use and which template to use. In fact, you can even create your own template. Learn how through of the links you'll see when you click the Donate link.

LiPS can also create posts for each position in your profile, allowing you to add more detail, such as (ex) coworkers adding their appreciation in working with you through the comments system build into WordPress&trade;. Posts maintained by this tool are filtered from your "normal" blog stream, but you can link to them from any other page. You can use
a different template for the post content too.

Really impress an employer or client? Add your StackExchange reputation from one of their major sites to your resume. Just select the site you registered on and enter your login or account id. Your account details will be automatically included when you update your profile page.

One more thing that needs to be clear. You're using this tool at your own risk. I'm not responsible for any type of damages caused by this tool.

Do you think you found a bug? Do you want additional features or help? Contact me through http://www.tenberge-ict.nl/contact/english/.

== Installation ==

This section describes how to install the plugin and get it working.

1. Upload `lips` to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Open the WordPress Dashboard, click Tools and LinkedIn&reg; Profile Sync.

== Frequently Asked Questions ==

= What's with these pop-ups? =
These are there due to my understanding of the LinkedIn&reg; terms of service. LinkedIn Corporation wants the user to have some way of verifying where the data is from.

= Why do I need to authorize the plugin every time I want to fetch my profile? =
This makes sure nothing unexpected happens to your LinkedIn profile, even when the the plugin just requests read only access.

= Why does this plugin require full member access? ==
LiPS needs access to experience, education, skills and recommendations. These sections are not available when the r_basicprofile permission is used.

== Screenshots ==

1. The LinkedIn&reg; Profile Sync page after initial installation.
2. The page changes when the OAuth token and secret are provided.
3. Authorize the plugin using the Authentication dialog box and a verifier.

== Changelog ==
= 0.8.15 =
* Fixed URL query string used for profile request; thanks Adam.
* Made the dialog size match the content for the dialog that shows the error.
* 0.8.13 was actually broken due to an incorrect tag
= 0.8.13 =
* Gets the full profile again using r_fullprofile.
= 0.8.12 =
* Displays tabs again. Reported by Neil Koch
* Separated the jQuery distributed css and the customizations done for this plugin.
= 0.8.11 =
* Fixed another couple of pass-by-reference fatal error. Reported by Mark Theloosen.
* The plugin now works on PHP 5.4 as well.
= 0.8.10 =
* Fixed a pass-by-reference fatal error. Reported by Mark.
= 0.8.9 =
* Encountered an incompatibility between this plugin and Tweet Blender. Reported by Jay Collier.
= 0.8.8 =
* Removed the PECL OAuth dependency, included the OAuth API found on http://code.google.com/p/oauth-php/.
* Changed the main plugin page, allowing basic configuration from the first tab.
= 0.8.7 =
* Fixed an error message raised on PHP 5.4. Reported by Martin Mayer.
* Fixed an foreach error when the OAuth extension is not available.
= 0.8.6 =
* Uninstallation fails when the OAuth extension was not available. Reported by Richard Dunn.
= 0.8.5 =
* Removed curl as a fixed request engine for the OAuth PECL module because of an error. Reported by Senad Aruc.
* PHP needs to be compiled with Curl in order to download the profile data from Stack Exchange. Disabled the option when the platform does not have Curl.
= 0.8.4 =
* Fixed error in jQuery scripting.
= 0.8.3 =
* Fixed error in jQuery when pressing the Save button without downloading the profile.
* Added support for Stack Exchange QA sites.
= 0.8.2 =
* Initial release. 0.8.0 was under active development

== Upgrade Notice ==
= any =
This plugin uses metadata stored in the WordPress database. This metadata gets regenerated each time the plugin is activated. The template metadata has changed in 0.8.2, so you'll need to deactivate and activate the
plugin right after installation. This is a generic update issue, not limited to any specific version. You'll need to do this when you moved your site to another subdirectory as well.

== Arbitrary section ==
= Things to do =
 * it's probably a good idea to encrypt the OAuth authenticated tokens with
  some form of a password, because anybody with access to the MySQL database
  will have access to port of the authentication details.
 * verify whatever a template generates -> the page template must start with a &lt;h1> or
  &lt;div>, same for a post.
 * allow a user to remove languages, thus shortening the language list.
 * optimize the jQuery code. It's a bit bloated and contains different styles at once.
 * ...

