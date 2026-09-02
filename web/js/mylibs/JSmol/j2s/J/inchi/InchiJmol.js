Clazz.declarePackage("J.inchi");
Clazz.load(["org.iupac.InChIStructureProvider", "J.api.JmolInChI"], "J.inchi.InchiJmol", ["JU.PT", "J.inchi.InchiToSmilesConverter"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.inputInChI = false;
this.inchi = null;
this.smilesOptions = null;
this.doGetSmiles = false;
this.getInchiModel = false;
this.getKey = false;
this.optionalFixedH = false;
Clazz.instantialize(this, arguments);}, J.inchi, "InchiJmol", null, [J.api.JmolInChI, org.iupac.InChIStructureProvider]);
Clazz.defineMethod(c$, "setParameters", 
function(options, molData, atoms, vwr){
if (atoms == null ? molData == null : atoms.isEmpty()) return null;
if (options == null) options = "";
var inchi = null;
var lc = options.toLowerCase().trim();
var getSmiles = (lc.indexOf("smiles") == 0);
var getInchiModel = (lc.indexOf("model") == 0);
var getKey = (lc.indexOf("key") >= 0);
var smilesOptions = (getSmiles ? options : null);
if (lc.startsWith("model/")) {
inchi = options.substring(10);
options = lc = "";
} else if (getInchiModel) {
lc = lc.substring(5);
}var optionalFixedH = (options.indexOf("fixedh?") >= 0);
var inputInChI = ((typeof(molData)=='string') && (molData).startsWith("InChI="));
if (inputInChI) {
inchi = molData;
} else {
options = lc;
if (getKey) {
options = options.$replace("inchikey", "");
options = options.$replace("key", "");
}if (optionalFixedH) {
var fxd = this.getInchi(vwr, atoms, molData, options.$replace('?', ' '));
options = JU.PT.rep(options, "fixedh?", "");
var std = this.getInchi(vwr, atoms, molData, options);
inchi = (fxd != null && fxd.length <= std.length ? std : fxd);
}}this.optionalFixedH = optionalFixedH;
this.inputInChI = inputInChI;
this.doGetSmiles = getSmiles;
this.inchi = inchi;
this.getKey = getKey;
this.smilesOptions = smilesOptions;
this.getInchiModel = getInchiModel;
return options;
}, "~S,~O,JU.BS,JV.Viewer");
Clazz.defineMethod(c$, "getSmiles", 
function(vwr, smilesOptions){
return  new J.inchi.InchiToSmilesConverter(this).getSmiles(vwr, smilesOptions);
}, "JV.Viewer,~S");
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
