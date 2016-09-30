# wondercms
WonderCMS - re-built from scratch

Used Object-oriented programming style.
Separate logic from markup for readability and maintainability.
Used JSON as database, is lightweight and easy to read and write, also to avoid creation of many files and separate config from pages.
Fixed all security holes (Local File Inclusion, Cross-site scripting).
Used `password_hash()` instead of `md5()`. MD5 can be brute-forced too easily.
Password can be changed after login successfully, which make sense to me.
Used [Trumbowyg](https://alex-d.github.io/Trumbowyg) as default editor. 
Other improvements...
