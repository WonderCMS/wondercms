// SummerNote plugin for WonderCMS, JavaScript
(function (factory) {
    if (typeof define === 'function' && define.amd) {
        define(['jquery'], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory(require('jquery'));
    } else {
        factory(window.jQuery);
    }
}(function ($) {

    var filetype = 'docs';

    $.extend($.summernote.plugins, {

        'files': function(context) {
            var self = this;

            self.filetype = '';
            self.file = '';
            self.range = '';

            var ui = $.summernote.ui;
            var $editor = context.layoutInfo.editor;
            var options = context.options;

            context.memo('button.files', function() {
                var button = ui.button({
                    contents: '<i class="glyphicon glyphicon-folder-open"/>',
                    tooltip: 'files',
                    click: context.createInvokeHandler('files.showDialog', 'docs')
                });
                var $files = button.render();
                return $files;
            });

            self.initialize = function() {
                var $container = options.dialogsInBody ? $(document.body) : $editor;

                var body =  '<div class="form-group row-fluid" id="filesDialog">'+
                '<div id="filesList" style="padding-left: 10px;"></div>'+
                '<form class="form-inline" id="fileUpload" enctype="multipart/form-data">'+
                '<div id="fileUrlDiv" class="form-group" style="width: 100%; padding-top: 10px">'+
                    '<label for="fileUrl">URL of image or document</label><input type="text" class="form-control" name="fileUrl" id="fileUrl" style="width: 100%" />'+
                '</div>'+
                '</form>'+
                '</div>';
                var footer = '<button href="#" class="btn btn-primary ext-files-btn">Insert</button>';

                self.$dialog = ui.dialog({
                    title: 'Insert image or document (Copy link to file Settings -> Files)',
                    fade: options.dialogsFade,
                    body: body,
                    footer: footer
                }).render().appendTo($container);
            };

            this.destroy = function() {
                this.$dialog.remove();
                this.$dialog = null;
                this.$panel.remove();
                this.$panel = null;
            };

            self.showDialog = function(t) {
                context.invoke('editor.saveRange');

                self.$dialog.find('#file').val('');
                self.$dialog.find('#fileUrlDiv').val('');

                self
                .openDialog(t)
                .then(function(dialogData) {
                    ui.hideDialog(self.$dialog);
                    context.invoke('editor.restoreRange');
                })
                .fail(function() {
                    context.invoke('editor.restoreRange');
                });
            };

            self.openDialog = function(t) {

                self.filetype = t;
                self.file = '';
                self.range = context.invoke('editor.createRange');

                return $.Deferred(function(deferred) {
                    var $dialogBtn = self.$dialog.find('.ext-files-btn');

                    ui.onDialogShown(self.$dialog, function() {
                        context.triggerEvent('dialog.shown');

                        $dialogBtn
                        .click(function(event) {
                            event.preventDefault();

                            self.fileLocal = true;
                            if (self.file == '') {
                                if (self.$dialog.find('#fileUrl').val()!='') {
                                    self.file = self.$dialog.find('#fileUrl').val();
                                    self.fileLocal = false;
                                }
                            }
                            if (self.file != '') {
                                if (self.fileLocal) {
                                    var fileUrl = 'files/'+self.filetype+'/'+self.file;
                                } else {
                                    var fileUrl = self.file;
                                }
                                if (self.filetype=='images') {
                                    context.invoke('editor.restoreRange');
                                    context.invoke('editor.insertImage', fileUrl);
                                } else {
                                    context.invoke('editor.restoreRange');
                                    var node = document.createElement('a');
                                    $(node).attr('href', fileUrl).attr('target', '_blank').html(self.range.toString());
                                    context.invoke('editor.insertNode', node);
                                }
                                self.filetype = '';
                                self.file = '';
                                self.$dialog.find('#fileUrl').val('');
                            }
                            deferred.resolve({ action: 'Files dialog OK clicked...' });
                        });
                    });

                    ui.onDialogHidden(self.$dialog, function() {
                        $dialogBtn.off('click');
                        if (deferred.state() === 'pending') {
                            deferred.reject();
                        }
                    });

                    ui.showDialog(self.$dialog);
                });
            };
        },

        'doc': function(context) {
            var ui = $.summernote.ui;
            context.memo('button.doc', function() {
                var button = ui.button({
                    contents: '<i class="glyphicon glyphicon-file"/>',
                    tooltip: 'Document',
                    click: context.createInvokeHandler('files.showDialog', 'docs')
                });
                var $doc = button.render();
                return $doc;
            });
        },

        'image': function(context) {
            var ui = $.summernote.ui;
            context.memo('button.image', function() {
                var button = ui.button({
                    contents: '<i class="glyphicon glyphicon-picture"/>',
                    tooltip: 'Image',
                    click: context.createInvokeHandler('files.showDialog', 'images')
                });
                var $image = button.render();
                return $image;
            });
        }
    });
}));
