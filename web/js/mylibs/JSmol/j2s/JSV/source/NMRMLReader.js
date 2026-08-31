Clazz.declarePackage("JSV.source");
Clazz.load(["JSV.source.XMLReader"], "JSV.source.NMRMLReader", ["java.nio.ByteBuffer", "$.ByteOrder", "JU.Base64", "$.PT", "JSV.source.JDXSource", "JU.Elements"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.structure = null;
this.assignment = null;
this.dim = 1;
Clazz.instantialize(this, arguments);}, JSV.source, "NMRMLReader", JSV.source.XMLReader);
Clazz.makeConstructor(c$, 
function(){
Clazz.superConstructor (this, JSV.source.NMRMLReader, []);
});
Clazz.overrideMethod(c$, "getXML", 
function(br){
try {
this.source =  new JSV.source.JDXSource(0, this.filePath);
this.getSimpleXmlReader(br);
this.parser.nextEvent();
this.processXML(13, 33);
if (!this.checkPointCount()) return null;
this.xFactor = 1;
this.yFactor = 1;
this.populateVariables();
} catch (pe) {
if (Clazz.exceptionOf(pe, Exception)){
System.err.println("That file may be empty...");
this.errorLog.append("That file may be empty... \n");
} else {
throw pe;
}
}
this.processErrors("nmrML");
try {
br.close();
} catch (e1) {
if (Clazz.exceptionOf(e1,"java.io.IOException")){
} else {
throw e1;
}
}
return this.source;
}, "java.io.BufferedReader");
Clazz.overrideMethod(c$, "processTag", 
function(tagId){
System.out.println(JSV.source.XMLReader.tagNames[tagId]);
switch (tagId) {
case 13:
var val = this.parser.getAttrValue("name").$replace(" atom", "");
this.obNucleus = (JU.PT.isDigit(val.charAt(0)) ? val : JU.Elements.getNmrNucleusFromName(val.toLowerCase()));
return true;
case 14:
this.strObFreq = this.parser.getAttrValueLC("value");
this.obFreq = Double.parseDouble(this.strObFreq);
return true;
case 15:
this.dim = 1;
this.npoints = Integer.parseInt(this.parser.getAttrValue("numberOfDataPoints"));
break;
case 32:
var type = this.parser.getAttrValue("byteFormat");
switch (type) {
case "complex128":
this.getXYFromBase64Complex128(this.parser.getCharacters());
break;
case "float64":
this.getXYFromBase64Float64(this.parser.getCharacters());
break;
default:
System.err.println("NMRML spectrum data array type unknown: " + type);
}
break;
case 18:
this.title = this.parser.getAttrValue("name");
break;
case 20:
this.structure = JSV.source.NMRMLReader.toCML(this.parser.getInnerXML());
break;
case 25:
this.assignment = this.parser.getOuterXML();
break;
case 23:
break;
}
return false;
}, "~N");
c$.toCML = Clazz.defineMethod(c$, "toCML", 
function(structure){
structure = JU.PT.rep(structure, "x=", "x3=");
structure = JU.PT.rep(structure, "y=", "y3=");
structure = JU.PT.rep(structure, "z=", "z3=");
structure = JU.PT.rep(structure, "atomList", "atomArray");
structure = JU.PT.rep(structure, "bondList", "bondArray");
structure = JU.PT.rep(structure, "atomRefs", "atomRefs2");
structure = JU.PT.rep(structure, ">\"", ">");
structure = "<cml>\n<molecule>\n" + structure + "\n</molecule>\n</cml>";
return structure;
}, "~S");
Clazz.overrideMethod(c$, "processEndTag", 
function(tagId){
}, "~N");
Clazz.defineMethod(c$, "getXYFromBase64Float64", 
function(sdata){
var bytes = JU.Base64.decodeBase64(sdata);
var b = java.nio.ByteBuffer.wrap(bytes).order(java.nio.ByteOrder.LITTLE_ENDIAN).asDoubleBuffer();
System.out.println(this.npoints + " " + Clazz.doubleToInt(bytes.length / 16));
if ((bytes.length % 16) != 0) {
throw  new RuntimeException("NMRMLReader byte length not multiple of 16 " + bytes.length);
}try {
var n = Clazz.doubleToInt(bytes.length / 16);
this.xaxisData =  Clazz.newDoubleArray (n, 0);
this.yaxisData =  Clazz.newDoubleArray (n, 0);
for (var i = 0; i < n; i++) {
this.xaxisData[i] = b.get();
this.yaxisData[i] = b.get();
}
this.npoints = n;
this.firstX = this.xaxisData[0];
this.deltaX = this.xaxisData[1] - this.firstX;
this.increasing = false;
this.continuous = true;
this.lastX = this.xaxisData[this.npoints - 1];
this.yUnits = "";
this.firstY = this.yaxisData[0];
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
e.printStackTrace();
} else {
throw e;
}
}
}, "~S");
Clazz.defineMethod(c$, "getXYFromBase64Complex128", 
function(sdata){
var bytes = JU.Base64.decodeBase64(sdata);
var b = java.nio.ByteBuffer.wrap(bytes).order(java.nio.ByteOrder.LITTLE_ENDIAN).asDoubleBuffer();
if ((bytes.length % 16) != 0) {
throw  new RuntimeException("NMRMLReader byte length not multiple of 16 " + bytes.length);
}try {
var n = Clazz.doubleToInt(bytes.length / 16);
this.xaxisData =  Clazz.newDoubleArray (n, 0);
this.yaxisData =  Clazz.newDoubleArray (n, 0);
for (var i = 0; i < n; i++) {
this.xaxisData[n - i - 1] = b.get();
this.yaxisData[n - i - 1] = b.get();
}
this.npoints = n;
this.firstX = this.xaxisData[0];
this.deltaX = this.xaxisData[1] - this.firstX;
this.increasing = false;
this.continuous = true;
this.lastX = this.xaxisData[this.npoints - 1];
this.yUnits = "";
this.firstY = this.yaxisData[0];
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
e.printStackTrace();
} else {
throw e;
}
}
}, "~S");
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
