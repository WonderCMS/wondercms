var changing = false;

$(document).ready(function() {
	$('[data-toggle="tooltip"]').tooltip();

	$('span.editText').click(function() {
		if (changing)return;
		a = $(this);
		title=(a.attr('title'))?title="\""+a.attr('title')+"\" ":"";

		if (a.hasClass('richTextEditor')) {
			<?php
				$pluginWhitelist = array('richTextEditor.php');
				$pluginRequest = $_REQUEST['plugin'];

				if (isset($_REQUEST['plugin']) && in_array($pluginRequest, $pluginWhitelist) && file_exists($pluginRequest)) {
					include ($pluginRequest);
				} else {
					echo "Plugin not whitelisted.";
				}
			?>
		}
		else {
			a.html("<textarea "+title+" id=\""+ a.attr('id') +"_field\" onblur=\"fieldSave(a.attr('id'),nl2br(this.value));\">" + a.html().replace(/<br>/gi, "") + "</textarea>");
			a.children(':first').focus();
		}

		autosize($('textarea'));
		changing = true;
	});
});

function nl2br(s) {
	return (s + '').replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g, '$1<br />$2');
}

function fieldSave(key,val) {
	$('#save').show();
	$.post('index.php?page=<?php echo htmlspecialchars($_GET["page"]); ?>', {fieldname: key, content: val}, function(){window.location.reload();});
}
