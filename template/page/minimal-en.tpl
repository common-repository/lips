<!--
template_name: LiPS minimal (table)
template_version: 0.9
template_lang: en
template_statics: initials_and_last_name, residence
template_base_weblink: http://www.tenberge-ict.nl/tools/wordpress/lips/wp-lips-template/
template_sample_weblink: http://www.tenberge-ict.nl/profiel
template_query: utm_source=wp-lips, utm_medium=plugin-static-min-en, utm_campaign=free-plugin
-->
<div class="wrap">
<hr />
<h2>{$lips.formattedName}</h2>
{$statics.residence} | <a href="{$lips.publicProfileUrl}">{$lips.publicProfileUrl}</a>
<hr />
<h2 class="lips-section">education</h2>
<table>
 {foreach from=$lips.educations.values item=edu}
  <tr>
   <td colspan="2">{$edu.schoolName|upper}</td>
  </tr>
  <tr>
   <td><em>{$edu.fieldOfStudy}</em></td>
   <td>{$edu.startDate.year} - {$edu.endDate.year}</td>
  </tr>
 {/foreach}
</table>
<h2 class="lips-section" lang="en">work experience</h2>
<table>
 {assign var=prev_position_company_name value=""}
 {foreach from=$lips.x_lips.positions item=position_container}
 {foreach from=$position_container item=position}
 <tr>
  {if $position.company.name neq $prev_position_company_name}
  {assign var=company_website value=$lips.x_lips.company.{$position.company.name}.websiteUrl}
  <td colspan="3"><strong>{if $company_website neq ""}<a href="{$company_website}">{/if}{$position.company.name|upper}{if $company_website neq ""}</a>{/if}</strong></td>
  </tr>
  <tr>{/if} {* different company name *}
  <td><span class="lips-position">{$position.title}</span></td>
  <td>{$position.startDate.year}{if $position.startDate.month neq ""}-{/if}{$position.startDate.month}</td>
  <td>{if $position.isCurrent eq true}today{else}{$position.endDate.year}{if $position.startDate.month neq ""}-{/if}{$position.endDate.month}{/if}</td>
 </tr>
 <tr>
  <td colspan="3">{$position.summary}</td>
  {assign var=post_href value=""}
  {if $lips.x_lips.has_post_uri}
  {assign var=post_href value=$lips.x_lips.uri.{$position.id}.uri}
  {/if}
  {if $post_href neq ""}</tr><tr><td colspan="3">Additional details in <a href="{$post_href}">here</a>{/if}
 </tr>
 {assign var=prev_position_company_name value=$position.company.name}
 {/foreach} {* position *}
 {/foreach} {* position_container *}
</table>
{if $lips.recommendationsReceived._total > 0}
<h2 class="lips-section" lang="en">recommendation</h2>
<table>
 {foreach from=$lips.recommendationsReceived.values item=recommendation}
 <tr><td><blockquote>{$recommendation.recommendationText}<p><em><a href="{$lips.x_lips.recommendation.{$recommendation.id}.publicProfileUrl}">{$recommendation.recommender.firstName} {$recommendation.recommender.lastName}</a></em></p></blockquote></td></tr>
 {/foreach}
 </table>
{/if} {* has recommendations *}
{if $lips.certifications._total > 0 } 
<h2 class="lips-section" lang="en">certification</h2>
<table>
 {foreach from=$lips.certifications.values item=certification}
  <tr><td>{$certification.name}</td></tr>
 {/foreach} {* certification *}
</table>
{/if} {* has certifications *}
{if $lips.courses._total > 0}
<h2 class="lips-section" lang="en">course</h2>
<table>
 {foreach from=$lips.courses.values item=course}
  <tr>
  <td>{$course.name}  {if ! empty($course.number)} ({$course.number}) {/if} </td>
  </tr>
 {/foreach}
</table>
{/if}
<p>
{assign var=lm_epoch value=$lips.lastModifiedTimestamp*0.001}
<span class="entry-meta">This page is last modified on {$smarty.now|date_format:"%Y-%m-%dT%H:%M"}, using LinkedIn&reg; data dated {$lm_epoch|date_format:"%Y-%m-%dT%H:%M"}</span>
</p> 
</div>
{* $Id: minimal-en.tpl 567529 2012-07-04 20:39:17Z bastb $ *}