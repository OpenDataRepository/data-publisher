Clazz.declarePackage("J.adapter.readers.xml");
Clazz.load(["J.adapter.readers.xml.XmlCmlReader"], "J.adapter.readers.xml.XmlNmrmlReader", ["java.io.BufferedReader"], function(){
var c$ = Clazz.declareType(J.adapter.readers.xml, "XmlNmrmlReader", J.adapter.readers.xml.XmlCmlReader);
Clazz.makeConstructor(c$, 
function(){
Clazz.superConstructor (this, J.adapter.readers.xml.XmlNmrmlReader, []);
});
Clazz.overrideMethod(c$, "previewXML", 
function(reader){
return ((Clazz.isClassDefined("J.adapter.readers.xml.XmlNmrmlReader$1") ? 0 : J.adapter.readers.xml.XmlNmrmlReader.$XmlNmrmlReader$1$ ()), Clazz.innerTypeInstance(J.adapter.readers.xml.XmlNmrmlReader$1, this, null, reader));
}, "java.io.BufferedReader");
Clazz.defineMethod(c$, "processStart2", 
function(name){
name = J.adapter.readers.xml.XmlNmrmlReader.toCML(name);
name = name.toLowerCase();
switch (name) {
case "atom":
this.atts.put("x3", this.atts.remove("x"));
this.atts.put("y3", this.atts.remove("y"));
this.atts.put("z3", this.atts.remove("z"));
break;
case "bond":
this.atts.put("atomrefs2", this.atts.remove("atomrefs"));
break;
}
Clazz.superCall(this, J.adapter.readers.xml.XmlNmrmlReader, "processStart2", [name]);
}, "~S");
Clazz.defineMethod(c$, "processEnd2", 
function(name){
Clazz.superCall(this, J.adapter.readers.xml.XmlNmrmlReader, "processEnd2", [J.adapter.readers.xml.XmlNmrmlReader.toCML(name)]);
}, "~S");
c$.toCML = Clazz.defineMethod(c$, "toCML", 
function(name){
name = name.toLowerCase();
switch (name) {
case "structure":
name = "molecule";
break;
case "atomlist":
name = "atomarray";
break;
case "bondlist":
name = "bondarray";
break;
}
return name;
}, "~S");
c$.$XmlNmrmlReader$1$=function(){
/*if5*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
Clazz.prepareCallback(this, arguments);
this.checked = false;
Clazz.instantialize(this, arguments);}, J.adapter.readers.xml, "XmlNmrmlReader$1", java.io.BufferedReader);
Clazz.defineMethod(c$, "read", 
function(cbuf, off, len){
var n = Clazz.superCall(this, J.adapter.readers.xml.XmlNmrmlReader$1, "read", [cbuf, off, len]);
if (!this.checked && len > 1000) {
var i = 200;
while (++i < 1000) {
if (cbuf[i] == '<' && cbuf[i + 1] == 'c' && cbuf[i + 2] == 'v') {
while (++i < 500) {
if (cbuf[i] == '>' && cbuf[i - 1] == '"') {
cbuf[i - 1] = '/';
cbuf[i - 2] = '"';
break;
}}
break;
}}
if (i >= 500) i = 200;
while (++i < 1000) {
if (cbuf[i] == '<' && cbuf[i + 1] == 'c' && cbuf[i + 2] == 'h') {
while (++i < 1000) {
if (cbuf[i] == '>') break;
if (cbuf[i] == '<') {
cbuf[i - 1] = '>';
break;
}}
break;
}}
this.checked = true;
}return n;
}, "~A,~N,~N");
/*eoif5*/})();
};
});
;//5.0.1-v7 Mon Aug 24 09:29:37 CDT 2026
