<!--
template_name: LiPS minimal (table)
template_version: 0.9
template_lang: nl
template_statics: initialen_achternaam, woonplaats, meer_info
template_base_weblink: http://www.tenberge-ict.nl/tools/wordpress/lips/wp-lips-template/
template_sample_weblink: http://www.tenberge-ict.nl/profiel
template_query: utm_source=wp-lips, utm_medium=plugin-static-min-nl, utm_campaign=free-plugin
template_constant: elementary=Elementaire kennis
template_constant: limited_working=Beperkte kennis
template_constant: professional_working=Professionele werkvaardigheden
template_constant: full_professional=Zo goed als moedertaal
template_constant: native_or_bilingual=Moedertaal of tweetalig
-->
<div class="wrap">
<h2 lang="nl">Personalia</h2>
<table>
<tr><td>Naam</td><td>{$statics.initialen_achternaam}</td></tr>
 <tr><td>Roepnaam</td><td>{$lips.firstName}</td></tr>
 <tr><td>Woonplaats</td><td>{$statics.woonplaats}</td></tr>
 <tr><td>@LinkedIn</td><td><a href="{$lips.publicProfileUrl}">{$lips.publicProfileUrl}</a></td></tr>
</table>
<h2 lang="nl">Opleiding</h2>
<table>
 {foreach from=$lips.educations.values item=edu}
  <tr>
   <td>{$edu.startDate.year}</td>
   <td>{$edu.endDate.year}</td>
   <td>{$edu.schoolName} - {$edu.fieldOfStudy}</td>
  </tr>
 {/foreach}
</table>
<h2>Werkervaring</h2>
<table>
 {assign var=prev_position_company_name value=""}
 {foreach from=$lips.x_lips.positions item=position_container}
 {foreach from=$position_container item=position}
 <tr>
  {if $position.company.name neq $prev_position_company_name}
  {assign var=company_website value=""}
  {if $lips.x_lips.company.{$position.company.name}}
  {assign var=company_website value=$lips.x_lips.company.{$position.company.name}.websiteUrl}
  {/if}
  <td colspan="3"><span class="lips-company-name">{if $company_website neq ""}<a href="{$company_website}" class="lips-company-link">{/if}{$position.company.name}{if $company_website neq ""}</a>{/if}</span></td>
  </tr>
  <tr>{/if} {* different company name *}
  <td><span class="lips-position-title">{$position.title}</span></td>
  {assign var=month_begin value=""}
  {if $position.startDate.month neq ""}
  {assign var=month_begin value='-'|cat:$position.startDate.month }
  {/if}
  {assign var=year_end value=""}
  {assign var=month_end value=""}
  {assign var=year_end value=$position.endDate.year}
   {if $position.endDate.month neq ""}
    {assign var=month_end value='-'|cat:$position.startDate.month }
   {/if}
  <td>{$position.startDate.year}{$month_begin}</td>
  <td>{if $position.isCurrent eq true}heden{else}{$year_end}{$month_end}{/if}</td>
 </tr>
 <tr>
  <td colspan="3"><span class="lips-position-summary">{$position.summary}</span></td>
  {assign var=post_href value=""}
  {if $lips.x_lips.has_post_uri}
  {assign var=post_href value=$lips.x_lips.uri.{$position.id}.uri}
  {/if}
  {if $post_href neq ""}</tr><tr><td colspan="3"><a href="{$post_href}" class="lips-post-link">Meer details hier.</a>{/if}
 </tr>
 {assign var=prev_position_company_name value=$position.company.name}
 {/foreach} {* position *}
 {/foreach} {* position_container *}
</table>
{if $lips.recommendationsReceived._total > 0}
<h2 lang="nl">Aanbeveling</h2>
<table>
 {foreach from=$lips.recommendationsReceived.values item=recommendation}
 <tr><td><blockquote>{$recommendation.recommendationText}<p class="lips-recommendation-text"><a href="{$lips.x_lips.recommendation.{$recommendation.id}.publicProfileUrl}" class="lips-recommendation-link">{$recommendation.recommender.firstName} {$recommendation.recommender.lastName}</a></p></blockquote></td></tr>
 {/foreach}
 </table>
{/if} {* has recommendations *}
{if $lips.certifications._total > 0 } 
<h2 lang="nl">Certificering</h2>
<table>
 {foreach from=$lips.certifications.values item=certification}
  <tr><td><span class="lips-certification-name">{$certification.name}</span></td></tr>
 {/foreach} {* certificering *}
</table>
{/if} {* has certifications *}
{if $lips.courses._total > 0}
<h2 lang="nl">Cursus</h2>
<table>
 {foreach from=$lips.courses.values item=course}
  <tr>
  <td><span class="lips-course-name">{$course.name}</span> {if ! empty($course.number)} <span class="lips-course-number">({$course.number})</span>{/if} </td>
  </tr>
 {/foreach}
</table>
{/if} {* has courses *}
<h2>Eigenschappen, specialiteiten en skills</h2>
<h3>Eigenschappen</h3>
<span class="lips-li-summary">
{$lips.summary}
</span>
<h3>Specialiteiten</h3>
<span class="lips-li-specialty">
{$lips.specialties}
</span>
<h3>Kennis</h3>
<ul class="lips-li-knowledge">
 {if $lips.languages._total > 0}
 <li class="lips-li-lang-wrapper">Talen</li>
  <ul class="lips-li-lang">
   {foreach from=$lips.languages.values item=lang}
    <li class="lips-li-lang-detail">{$lang.language.name}: {$constants.{$lang.proficiency.level}}</li>
   {/foreach} 
  </ul>
 {/if}
 <li class="lips-li-skills">Actuele kennis van en ervaring met {foreach from=$lips.skills.values item=skill}{$skill.skill.name}{if $skill@iteration == $lips.skills._total - 1} en {elseif $skill@iteration < $lips.skills._total}, {/if}{/foreach}.</li>
 <li class="lips-li-interests">{$lips.interests}</li>
</ul>
{if $statics.meer_info neq ""}
<p><a href="{$statics.meer_info}">Hier</a> meer informatie.</p>
{/if}
<p class="lips-page-meta">
{assign var=lm_epoch value=$lips.lastModifiedTimestamp*0.001}
<span class="entry-meta">Deze pagina is bijgewerkt op {$smarty.now|date_format:"%Y-%m-%dT%H:%M"}, op basis van het LinkedIn&reg; profiel van {$lm_epoch|date_format:"%Y-%m-%dT%H:%M"}.</span></p> 
</div>
{* $Id: minimal-nl.tpl 573216 2012-07-16 19:40:24Z bastb $ *}