			a.html("<textarea "+title+"name=\"textarea\" id=\""+ a.attr('id') +"_field\" onblur=\"fieldSave(a.attr('id'),nl2br(this.value));\">" + a.html().replace(/<br>/gi, "") + "</textarea>");
			a.children(':first').focus().autosize().trigger('autosize.resize');
