Clazz.declarePackage("J.inchi");
Clazz.load(["J.inchi.InchiJmol"], "J.inchi.InChIJS", ["JU.PT", "org.iupac.InchiUtils"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.json = null;
this.atoms = null;
this.bonds = null;
this.stereo0d = null;
this.thisAtom = null;
this.thisBond = null;
this.thisStereo = null;
Clazz.instantialize(this, arguments);}, J.inchi, "InChIJS", J.inchi.InchiJmol);
Clazz.makeConstructor(c$, 
function(){
Clazz.superConstructor (this, J.inchi.InChIJS, []);
});
Clazz.overrideMethod(c$, "getInchi", 
function(vwr, atoms, molData, options){
var ret = "";
try {
if (options.equals("version")) {
{
return (Jmol.modelFromInchi ?
Jmol.modelFromInchi('').ver : "");
}}options = this.setParameters(options, molData, atoms, vwr);
if (options == null) return "";
options = JU.PT.rep(JU.PT.rep(options.$replace('-', ' '), "  ", " ").trim(), " ", " -").toLowerCase();
if (options.length > 0) options = "-" + options;
if (molData == null) molData = vwr.getModelExtract(atoms, false, false, "MOL");
if (this.inputInChI) {
if (this.doGetSmiles || this.getInchiModel) {
{
this.json = (Jmol.modelFromInchi ?
Jmol.modelFromInchi(molData).model : "");
if (this.json && !this.getInchiModel) {
this.json = JSON.parse(this.json);
}
}return (this.doGetSmiles ? this.getSmiles(vwr, this.smilesOptions) : this.json);
}{
ret = (Jmol.inchikeyFromInchi ?
Jmol.inchikeyFromInchi(molData).inchikey : "");
}} else {
var haveKey = (options.indexOf("key") >= 0);
if (haveKey) {
options = options.$replace("inchikey", "key");
}{
ret = (Jmol.inchiFromMolfile ?
Jmol.inchiFromMolfile(molData, options).inchi : "");
}}} catch (e) {
{
e = (e.getMessage$ ? e.getMessage$() : e);
}System.err.println("InChIJS exception: " + e);
}
return ret;
}, "JV.Viewer,JU.BS,~O,~S");
Clazz.overrideMethod(c$, "initializeModelForSmiles", 
function(){
{
this.atoms = this.json.atoms;
this.bonds = this.json.bonds;
this.stereo0d = this.json.stereo0d || [];
}});
Clazz.overrideMethod(c$, "getNumAtoms", 
function(){
{
return this.atoms.length;
}});
Clazz.overrideMethod(c$, "setAtom", 
function(i){
{
this.thisAtom = this.atoms[i];
}return this;
}, "~N");
Clazz.overrideMethod(c$, "getElementType", 
function(){
return this.getString(this.thisAtom, "elname", "");
});
Clazz.overrideMethod(c$, "getX", 
function(){
return this.getDouble(this.thisAtom, "x", 0);
});
Clazz.overrideMethod(c$, "getY", 
function(){
return this.getDouble(this.thisAtom, "y", 0);
});
Clazz.overrideMethod(c$, "getZ", 
function(){
return this.getDouble(this.thisAtom, "z", 0);
});
Clazz.overrideMethod(c$, "getCharge", 
function(){
return this.getInt(this.thisAtom, "charge", 0);
});
Clazz.overrideMethod(c$, "getImplicitH", 
function(){
return this.getInt(this.thisAtom, "implicitH", 0);
});
Clazz.overrideMethod(c$, "getIsotopicMass", 
function(){
var sym = this.getElementType();
var mass = 0;
{
mass = this.thisAtom["isotopicMass"] || 0;
}return org.iupac.InchiUtils.getActualMass(sym, mass);
});
Clazz.overrideMethod(c$, "getNumBonds", 
function(){
{
return this.bonds.length;
}});
Clazz.overrideMethod(c$, "setBond", 
function(i){
{
this.thisBond = this.bonds[i];
}return this;
}, "~N");
Clazz.overrideMethod(c$, "getIndexOriginAtom", 
function(){
return this.getInt(this.thisBond, "originAtom", 0);
});
Clazz.overrideMethod(c$, "getIndexTargetAtom", 
function(){
return this.getInt(this.thisBond, "targetAtom", 0);
});
Clazz.overrideMethod(c$, "getInchiBondType", 
function(){
return this.getString(this.thisBond, "type", "");
});
Clazz.overrideMethod(c$, "getNumStereo0D", 
function(){
{
return this.stereo0d.length;
}});
Clazz.overrideMethod(c$, "setStereo0D", 
function(i){
{
this.thisStereo = this.stereo0d[i];
}return this;
}, "~N");
Clazz.overrideMethod(c$, "getParity", 
function(){
return this.getString(this.thisStereo, "parity", "");
});
Clazz.overrideMethod(c$, "getStereoType", 
function(){
return this.getString(this.thisStereo, "type", "");
});
Clazz.overrideMethod(c$, "getCenterAtom", 
function(){
return this.getInt(this.thisStereo, "centralAtom", -1);
});
Clazz.overrideMethod(c$, "getNeighbors", 
function(){
{
return this.thisStereo.neighbors;
}});
Clazz.defineMethod(c$, "getInt", 
function(map, name, defaultValue){
{
var val = map[name]; if (val || val == 0) return val;
}return defaultValue;
}, "java.util.Map,~S,~N");
Clazz.defineMethod(c$, "getDouble", 
function(map, name, defaultValue){
{
var val = map[name]; if (val || val == 0) return val;
}return defaultValue;
}, "java.util.Map,~S,~N");
Clazz.defineMethod(c$, "getString", 
function(map, name, defaultValue){
{
var val = map[name]; if (val || val == "") return val;
}return defaultValue;
}, "java.util.Map,~S,~S");
{
try {
{
var j2sPath = J2S._applets.master._j2sPath;
J2S.inchiPath = j2sPath + "/_ES6";
$.getScript(J2S.inchiPath +   "/inchi-web-SwingJS.js");
}} catch (t) {
}
}});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
