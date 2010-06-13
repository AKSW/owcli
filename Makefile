INSTALLDIR="/usr/local"
VERSION="0.4"

default:
	@echo "This is a PHP project no need to compile something."
	@echo "If you wanna install owcli, type 'make install' (or 'sudo make install')."
	@echo "Current Install Directory is '$(INSTALLDIR)'."

install:
	mkdir -p $(INSTALLDIR)/share/owcli
	cp owcli.php $(INSTALLDIR)/share/owcli/
	cp dot.owcli $(INSTALLDIR)/share/owcli/
	mkdir -p $(INSTALLDIR)/man/man1/
	cp owcli.1 $(INSTALLDIR)/man/man1/owcli
	ln -s $(INSTALLDIR)/share/owcli/owcli.php $(INSTALLDIR)/bin/owcli

uninstall:
	rm -rf $(INSTALLDIR)/bin/owcli
	rm -rf $(INSTALLDIR)/share/owcli
	rm -rf $(INSTALLDIR)/man/man1/owcli

dist:
	mkdir -p owcli-$(VERSION)
	cp -R ChangeLog dot.owcli INSTALL Makefile owcli.1 owcli.php README owcli-$(VERSION)
	tar cjf owcli-$(VERSION).tar.bz2 owcli-$(VERSION)
	rm -rf owcli-$(VERSION)

