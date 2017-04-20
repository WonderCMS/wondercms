var g_changing = false,
    g_saved = '';

function nl2br(value) {
  return (value + "").replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g, "$1<br>$2")
}

function fieldSave(id, value, dataTarget) {
  g_changing = (g_saved!=value);
  if (!g_changing){
    $(this).parent().html(g_saved);
    return
  };
  $("#save").show();

  $.post(
    "", { fieldname: id, content: value, target: dataTarget },
    function(a) {}
  ).always(
    function() {
      window.location.reload();
    }
  )
}

function onEdit() {
  if (g_changing) return;
  var $this = $(this),
    title = $this.attr('title'),
    id    = $this.attr('id'),
    target= $this.data('target'),
    value = $this.hasClass('editable') ? ["", ""] : ["nl2br(", ")"];

  g_saved = $this.html();
  
  title = title ? '"'+title+'"' : '';
  value = value.join('this.value');

  var fBlur = 'fieldSave.call(this, '+[ "'"+id+"'", value, "'"+target+"'"].join(',')+')';

  $this.html('<textarea '+title+' id="'+id+'_field" onblur="'+fBlur+'">'+$this.html()+"</textarea>");
  $this.children(":first").focus();
  autosize($("textarea")), 
  g_changing = true;
}

function onReady() {
  $("span.editText").click(onEdit);
}

$(document).ready(onReady);