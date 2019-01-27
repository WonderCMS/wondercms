// Javascript for WonderCMS
$(document).tabOverride(!0, "textarea");

function nl2br(a) {
    return (a + "").replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g, "$1<br>$2");
}

function fieldSave(a, b, c, d, e) {
    $("#save").show(), $.post("", {
        fieldname: a,
        token: token,
        content: b,
        target: c,
        menu: d,
        visibility: e
    }, function(a) {}).always(function() {
        window.location.reload();
    });
}

var changing = !1;
$(document).ready(function() {
    $('body').on('click', 'div.editText', function() {
        changing || (a = $(this), title = a.attr("title") ? title = '"' + a.attr("title") + '" ' : "", a.hasClass("editable") ? a.html("<textarea " + title + ' id="' + a.attr("id") + '_field" onblur="fieldSave(a.attr(\'id\'),this.value,a.data(\'target\'),a.data(\'menu\'),a.data(\'visibility\'));">' + a.html() + "</textarea>") : a.html("<textarea " + title + ' id="' + a.attr("id") + '_field" onblur="fieldSave(a.attr(\'id\'),nl2br(this.value),a.data(\'target\'),a.data(\'menu\'),a.data(\'visibility\'));">' + a.html().replace(/<br>/gi, "\n") + "</textarea>"), a.children(":first").focus(), autosize($("textarea")), changing = !0);
    });
    $('body').on('click', 'i.menu-toggle', function() {
        var a = $(this),
            c = (setTimeout(function() {
                window.location.reload();
            }, 500), a.attr("data-menu"));
        a.hasClass("menu-item-hide") ? (a.removeClass("glyphicon-eye-open menu-item-hide").addClass("glyphicon-eye-close menu-item-show"), a.attr("title", "Hide page from menu").attr("data-visibility", "hide"), $.post("", {
            fieldname: "menuItems",
            token: token,
            content: " ",
            target: "menuItemVsbl",
            menu: c,
            visibility: "hide"
        }, function(a) {})) : a.hasClass("menu-item-show") && (a.removeClass("glyphicon-eye-close menu-item-show").addClass("glyphicon-eye-open menu-item-hide"), a.attr("title", "Show page in menu").attr("data-visibility", "show"), $.post("", {
            fieldname: "menuItems",
            token: token,
            content: " ",
            target: "menuItemVsbl",
            menu: c,
            visibility: "show"
        }, function(a) {}));
    }), $('body').on('click', '.menu-item-add', function() {
        var newPage = prompt("Enter page name");
        if (!newPage) {
            return !1;
        }
        newPage = newPage.replace(/[`~;:'",.<>\{\}\[\]\\\/]/gi, '').trim();
        $.post("", {
            fieldname: "menuItems",
            token: token,
            content: newPage,
            target: "menuItem",
            menu: "none",
            visibility: "show"
        }, function(a) {}).done(setTimeout(function() {
            window.location.reload();
        }, 500));
    });
    $('body').on('click', '.menu-item-up,.menu-item-down', function() {
        var a = $(this),
            b = (a.hasClass('menu-item-up')) ? '-1' : '1',
            c = a.attr("data-menu");
        $.post("", {
            fieldname: "menuItems",
            token: token,
            content: b,
            target: "menuItemOrder",
            menu: c,
            visibility: ""
        }, function(a) {}).done(function() {
            $('#menuSettings').parent().load("index.php #menuSettings", {
                func: "getMenuSettings"
            });
        });
    });
});
