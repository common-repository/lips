jQuery(document).ready(function(a){var k=a(".wrap h3").wrap('<div class="ui-tabs-panel">');k.each(function(){a(this).parent().append(a(this).parent().nextUntil("div.ui-tabs-panel"))});a(".ui-tabs-panel").each(function(b){a(this).attr("id",sections[a(this).children("h3").text()]);b>0&&a(this).addClass("ui-tabs-hide")});a(".ui-tabs").tabs({fx:{opacity:"toggle",duration:"fast"}});a("input[type=text], textarea").each(function(){(a(this).val()==a(this).attr("placeholder")||a(this).val()=="")&&a(this).css("color","#999")});a("input[type=text], textarea").focus(function(){if(a(this).val()==a(this).attr("placeholder")||a(this).val()==""){a(this).val("");a(this).css("color","#000")}}).blur(function(){if(a(this).val()==""||a(this).val()==a(this).attr("placeholder")){a(this).val(a(this).attr("placeholder"));a(this).css("color","#999")}});var e=[];jQuery("#profile_lang").find("option").each(function(b,a){e.push(a)});errors!=null&&a.each(errors,function(d,b){var c=a("<div id='"+d+"' />").html(b[1]).appendTo("body");c.dialog({dialogClass:"wp-dialog",modal:true,autoOpen:false,closeOnEscape:true,title:b[0],buttons:[{text:"Close",click:function(){a(this).dialog("close")}}]}).dialog("open")});jQuery("#update_profile").change(function(){visibility="none";if(a(this).is(":checked"))visibility="block";jQuery(".update_profile").css("display",visibility)}).change();jQuery("#profile_source0, #profile_source1").change(function(){var b=jQuery("#profile_lang").empty();if(a(this).val()=="li_profile"){jQuery("#keep_local_copy").removeAttr("disabled");jQuery.each(e,function(c,a){b.append(a)})}else{jQuery("#keep_local_copy").attr("disabled","disabled");jQuery.each(language_specific,function(d,c){b.append(a("<option></option>").attr("value",d).text(c))})}});jQuery("#installed_page_template").change(function(){meta_id=this.id+"-meta";meta_text="";id=jQuery("#installed_page_template :selected").val();if(a(this).val()=="custom"){jQuery(".custom_page_template").css("display","block");jQuery(".statics_container").css("display","none")}else{jQuery("#statics-description").html(statics.replace("%tpl",jQuery("#installed_page_template :selected").text()));meta_text='<a class="lips-ext-ref" href="'+sample_links[a(this).val()]+'" target="lips_review">'+sample_link_text+"</a>";jQuery(".custom_page_template, .lips-static").css("display","none");jQuery(".statics_container").css("display","block");jQuery(".statics_container, .lips-static."+id).css("display","block");visibility="block";if(0==jQuery(".lips-static."+id).length)visibility="none";jQuery(".statics_container").css("display",visibility)}jQuery("#"+meta_id).html(meta_text)}).change();jQuery("#have_posts").change(function(){if(a(this).is(":checked")){jQuery(".has_posts").css("display","block");d()}else jQuery(".has_posts").css("display","none")}).change();jQuery("#post_template0, #post_template1, #post_template2").change(function(){jQuery.each([".post_use_installed_template",".custom_post_template"],function(b,a){jQuery(a).css("display","none")});if(jQuery(".has_posts").css("display")=="block")if(this.id=="post_template1"&&a(this).is(":checked"))jQuery(".custom_post_template").css("display","block");else this.id=="post_template2"&&a(this).is(":checked")&&jQuery(".post_use_installed_template").css("display","block")});jQuery("#enable_profile_data_debug").change(function(){visibility="none";if(a(this).is(":checked")){visibility="block";jQuery("#profile_debug_data_page").val()==jQuery("#profile_page").val()&&b(jQuery("#profile_page option:selected").text())}jQuery(".has_profile_debug").css("display",visibility)}).change();function d(){jQuery.each([0,1,2],function(a){element_id="#post_template"+a;jQuery(element_id).is(":checked")&&jQuery(element_id).change()})}function f(){jQuery("#keep_local_copy").removeAttr("disabled");jQuery("#lips-form").submit()}function h(){jQuery("#save").attr("disabled",false);jQuery("#lips-saving").css("visibility","hidden")}function j(){jQuery("#lips-err-detail").removeClass("lips-err-monospace");jQuery("#lips-err-detail").text("Timeout");jQuery("#lips-err-box").dialog("open",title,"Problem contacting LinkedIn&reg;")}function c(a,b){sep_pos=a.indexOf(":");if(sep_pos>-1&&"0"==a.substring(0,sep_pos))b();else{jQuery("#lips-err-text").html("WordPress&trade; was unable to create the page.");jQuery("#lips-err-additional-detail").html("");jQuery("#lips-err-box").dialog({title:"Unable to create page"});if(-1==sep_pos)jQuery("#lips-err-detail").html("Unexpected result: <code>"+a+"</code>");else jQuery("#lips-err-detail").html(a.substring(sep_pos+1));jQuery("#lips-err-box").dialog("open")}}function i(a){sep_pos=a.indexOf(":");if(-1==sep_pos){jQuery("#lips-err-detail").html("Unexpected result: <code>"+a+"</code>");jQuery("#lips-err-box").dialog("open")}else if(a.substring(0,sep_pos)=="0"){jQuery("#pin").val("");jQuery("#oalink").html(a.substring(sep_pos+1));jQuery("#lips-pin-box").dialog("open");jQuery("#pin").focus();setTimeout(function(){jQuery("#lips-pin-box").dialog("close")},3e5)}else{jQuery("#lips-err-detail").addClass("lips-err-monospace");jQuery("#lips-err-detail").text(a.substring(sep_pos+1));jQuery("#lips-err-box").dialog("open")}jQuery("#lips-reset-button").attr("disabled",false)}jQuery("#lips-pin-box").dialog({dialogClass:"wp-dialog",autoOpen:false,height:300,width:400,modal:true,closeOnEscape:true,title:"Authorization required",buttons:[{text:"Fetch",click:function(){if(jQuery("#pin").val().length>0){a(this).dialog("close");g();jQuery("#lips-form").append(jQuery("#pin"));jQuery("#pin").css("visibility","hidden");f()}}},{text:"Cancel",click:function(){a(this).dialog("close")}}],close:function(){jQuery("#lips-saving").css("visibility","hidden");jQuery("#save").attr("disabled",false)}});jQuery("#lips-err-box").dialog({dialogClass:"wp-dialog",title:"Problem contacting LinkedIn&reg;",autoOpen:false,height:220,width:400,modal:true,closeOnEscape:true,buttons:[{text:"Close",click:function(){a(this).dialog("close")}}],close:function(){h()}});jQuery("#lips-page-box").dialog({dialogClass:"wp-dialog",autoOpen:false,height:220,width:400,modal:true,closeOnEscape:true,title:"Create a new page",buttons:[{text:"Create",click:function(){if(jQuery("#lips-page").val().length>0){jQuery.ajax({type:"POST",url:ajaxurl,data:{action:"lips",request:"create_page","page-usage":jQuery("#lips-page").data("page-usage"),specific:jQuery("#lips-page").val()},timeout:1e4,success:function(a){c(a,function(){window.location.reload(true)})},error:function(b,a){"timeout"==a&&c("1:Timeout while trying to create a page")}});a(this).dialog("close")}else jQuery("#lips-page").focus()}},{text:"Cancel",click:function(){a(this).dialog("close")}}],close:function(){}});jQuery("#save").click(function(){if(jQuery("#update_profile").is(":checked")){if(jQuery("#profile_page").val()==no_page_selection.page){jQuery("#lips-err-text").html("You did not select a profile page yet.");jQuery("#lips-err-additional-detail").html("You can select a Profile Page through the <em>Page Settings</em> tab. It's the first option.");jQuery("#lips-err-box").dialog({title:"Unable to save profile"});jQuery("#lips-err-box").dialog("open")}else if(jQuery("#enable_profile_data_debug").is(":checked")&&jQuery("#profile_debug_data_page").val()==no_page_selection.dbg){jQuery("#lips-err-text").html("You enabled the <em>Debug Data On-a-Page</em> option, but you did not select a page to store your debug profile on.");jQuery("#lips-err-additional-detail").html("Select a <em>Debug Data On-a-Page Title</em> from the <em>Development Setting</em> tab or disable the <em>Debug Data On-a-Page</em> option.");jQuery("#lips-err-box").dialog({title:"Unable to save profile"});jQuery("#lips-err-box").dialog("open")}else if(jQuery("#profile_source0").is(":checked")){jQuery("#lips-saving").css("visibility","visible");jQuery("#lips-reset-button").attr("disabled",true);a(this).attr("disabled",true);jQuery.ajax({type:"POST",url:ajaxurl,data:{action:"lips",request:"oalink"},timeout:1e4,success:function(a){i(a)},error:function(b,a){"timeout"==a&&j()}})}}else f()});function b(b){var c=a("<div id='lips-duplicate-page-use' />").html(dialog.duplicate.body+"<strong>"+b+"</strong>").appendTo("body");c.dialog({dialogClass:"wp-dialog",modal:true,autoOpen:false,closeOnEscape:true,title:dialog.duplicate.title,buttons:[{text:"Close",click:function(){a(this).dialog("close")}}]}).dialog("open")}function g(){var b=a("<div id='lips-submitting' />").html(dialog.submit.body).appendTo("body");b.dialog({dialogClass:"wp-dialog",modal:true,autoOpen:false,closeOnEscape:false,title:dialog.submit.title,open:function(){a(".ui-dialog-titlebar-close",this.parentNode).hide()}}).dialog("open")}jQuery("#profile_debug_data_page, #profile_page").change(function(){page=a("option:selected",this).text();if(jQuery("#enable_profile_data_debug").is(":checked")&&jQuery("#profile_debug_data_page").val()==jQuery("#profile_page").val()){b(page);if(a(this).data("previous_page")!=""){a(this).val(a(this).data("previous_page"));a(this).change()}}a(this).data("previous_page",a(this).val())});jQuery("#lips-about").click(function(){cur=a(".lips-help").css("display");req="block";if("block"==cur)req="none";a(".lips-help").css("display",req)});jQuery("#lips-close").click(function(){a(this).parent().css("display","none")});a(".wrap h3, .wrap table").show();jQuery("a.lips-tab-section").click(function(){if(a(this).attr("href")=="#li")jQuery("#lips-reset-button").show();else jQuery("#lips-reset-button").hide()});a(".warning").change(function(){if(a(this).is(":checked"))a(this).parent().css("background","#c00").css("color","#fff").css("fontWeight","bold");else a(this).parent().css("background","none").css("color","inherit").css("fontWeight","normal")});a(".lips-with-meta").each(function(b,a){jQuery("#"+a.id).change()});jQuery("#profile_source1").is(":checked")&&jQuery("#profile_source1").change();update_profile_class=jQuery("#update_profile").attr("class");update_profile_class!==undefined&&update_profile_class.indexOf("lips-identified-never-synced")>-1&&jQuery("#lips-speech-copy").css("display","block");if(autorun!=null)autorun=="autoCreatePageTask"&&handleRelates("lips-page-box");a.browser.mozilla&&a("form").attr("autocomplete","off");d()});function handleRelates(a){display_box=true;if("lips-profile-page-box"==a)jQuery("#lips-page").data("page-usage",page_usage.rt);else if("lips-debug-page-box"==a)jQuery("#lips-page").data("page-usage",page_usage.dev);else display_box=false;if(true==display_box){jQuery("#lips-page").val("");jQuery("#lips-page-box").dialog("open")}}