Clazz.declarePackage("J.adapter.readers.xml");
Clazz.load(["J.adapter.readers.xml.CDXMLParser", "$.XmlReader"], "J.adapter.readers.xml.XmlCdxReader", ["J.adapter.smarter.Atom", "$.Bond", "JU.Edge"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.no3D = false;
this.parser = null;
this.isCDX = false;
Clazz.instantialize(this, arguments);}, J.adapter.readers.xml, "XmlCdxReader", J.adapter.readers.xml.XmlReader, J.adapter.readers.xml.CDXMLParser.CDXReaderI);
Clazz.makeConstructor(c$, 
function(){
Clazz.superConstructor (this, J.adapter.readers.xml.XmlCdxReader, []);
this.parser =  new J.adapter.readers.xml.CDXMLParser(this);
});
Clazz.overrideMethod(c$, "processXml", 
function(parent, saxReader){
this.is2D = true;
if (parent == null) {
this.processXml2(this, saxReader);
parent = this;
} else {
this.no3D = parent.checkFilterKey("NO3D");
this.noHydrogens = parent.noHydrogens;
this.processXml2(parent, saxReader);
this.filter = parent.filter;
}}, "J.adapter.readers.xml.XmlReader,~O");
Clazz.overrideMethod(c$, "processStartElement", 
function(localName, nodeName){
this.parser.processStartElement(localName, this.atts);
}, "~S,~S");
Clazz.overrideMethod(c$, "processEndElement", 
function(localName){
this.parser.processEndElement(localName, this.chars.toString());
}, "~S");
Clazz.overrideMethod(c$, "finalizeSubclassReader", 
function(){
this.parser.finalizeParsing();
this.createMolecule();
});
Clazz.overrideMethod(c$, "getBondOrder", 
function(key){
switch (key) {
case "1":
case "single":
return 1;
case "2":
case "double":
return 2;
case "3":
case "triple":
return 3;
case "up":
return 1025;
case "down":
return 1041;
case "either":
return 1057;
case "null":
return 131071;
case "delocalized":
return 515;
default:
case "partial":
return JU.Edge.getBondOrderFromString(key);
}
}, "~S");
Clazz.overrideMethod(c$, "handleCoordinates", 
function(atts){
var hasXYZ = (atts.containsKey("xyz"));
var hasXY = (atts.containsKey("p"));
if (hasXYZ && (!this.no3D || !hasXY)) {
this.is2D = false;
this.parser.setAtom("xyz", atts);
} else if (atts.containsKey("p")) {
this.parser.setAtom("p", atts);
}}, "java.util.Map");
Clazz.defineMethod(c$, "createMolecule", 
function(){
var bs = this.parser.bsAtoms;
for (var i = bs.nextSetBit(0); i >= 0; i = bs.nextSetBit(i + 1)) {
var atom = this.parser.getAtom(i);
var a =  new J.adapter.smarter.Atom();
a.set(atom.x, atom.y, atom.z);
a.atomSerial = atom.intID;
a.elementNumber = atom.elementNumber;
a.formalCharge = atom.formalCharge;
var element = J.adapter.smarter.AtomSetCollectionReader.getElementSymbol(atom.elementNumber);
if (atom.isotope != null) element = atom.isotope + element;
this.setElementAndIsotope(a, element);
this.asc.addAtom(a);
}
bs = this.parser.bsBonds;
for (var i = bs.nextSetBit(0); i >= 0; i = bs.nextSetBit(i + 1)) {
var bond = this.parser.getBond(i);
var b =  new J.adapter.smarter.Bond(bond.atomIndex1, bond.atomIndex2, bond.order);
this.asc.addBondNoCheck(b);
}
this.parent.appendLoadNote((this.isCDX ? "CDX: " : "CDXML: ") + (this.is2D ? "2D" : "3D"));
this.asc.setInfo("minimize3D", Boolean.$valueOf(!this.is2D && !this.noHydrogens));
this.asc.setInfo("is2D", Boolean.$valueOf(this.is2D));
if (this.is2D) {
this.optimize2D = !this.noHydrogens && !this.noMinimize;
this.asc.setModelInfoForSet("dimension", "2D", this.asc.iSet);
this.set2D();
}});
Clazz.overrideMethod(c$, "warn", 
function(warning){
this.parent.appendLoadNote(warning);
}, "~S");
});
;//5.0.1-v7 Mon Aug 24 09:29:37 CDT 2026
