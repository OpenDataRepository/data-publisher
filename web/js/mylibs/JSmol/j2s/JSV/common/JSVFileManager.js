Clazz.declarePackage("JSV.common");
Clazz.load(["java.util.Hashtable"], "JSV.common.JSVFileManager", ["java.io.BufferedInputStream", "$.BufferedReader", "$.File", "$.InputStreamReader", "$.StringReader", "java.net.URL", "JU.AU", "$.BS", "$.Encoding", "$.JSJSONParser", "$.P3", "$.PT", "$.SB", "JSV.common.JSVersion", "$.JSViewer", "JSV.exception.JSVException", "JU.Logger"], function(){
var c$ = Clazz.declareType(JSV.common, "JSVFileManager", null);
Clazz.defineMethod(c$, "isApplet", 
function(){
return (JSV.common.JSVFileManager.appletDocumentBase != null);
});
c$.getFileAsString = Clazz.defineMethod(c$, "getFileAsString", 
function(name){
if (name == null) return null;
var br;
var sb =  new JU.SB();
try {
br = JSV.common.JSVFileManager.getBufferedReaderFromName(name);
var line;
while ((line = br.readLine()) != null) {
sb.append(line);
sb.appendC('\n');
}
br.close();
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
return null;
} else {
throw e;
}
}
return sb.toString();
}, "~S");
c$.getBufferedReaderForInputStream = Clazz.defineMethod(c$, "getBufferedReaderForInputStream", 
function($in){
try {
return  new java.io.BufferedReader( new java.io.InputStreamReader($in, "UTF-8"));
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
return null;
} else {
throw e;
}
}
}, "java.io.InputStream");
c$.getBufferedReaderForStringOrBytes = Clazz.defineMethod(c$, "getBufferedReaderForStringOrBytes", 
function(stringOrBytes){
return (stringOrBytes == null ? null :  new java.io.BufferedReader( new java.io.StringReader((typeof(stringOrBytes)=='string') ? stringOrBytes :  String.instantialize(stringOrBytes))));
}, "~O");
c$.getBufferedReaderFromName = Clazz.defineMethod(c$, "getBufferedReaderFromName", 
function(name){
if (name == null) throw  new JSV.exception.JSVException("Cannot find " + name);
JU.Logger.info("JSVFileManager getBufferedReaderFromName " + name);
var path = JSV.common.JSVFileManager.getFullPathName(name);
if (!path.equals(name)) JU.Logger.info("JSVFileManager getBufferedReaderFromName " + path);
return JSV.common.JSVFileManager.getUnzippedBufferedReaderFromName(path);
}, "~S");
c$.getFullPathName = Clazz.defineMethod(c$, "getFullPathName", 
function(name){
try {
if (JSV.common.JSVFileManager.appletDocumentBase == null) {
if (JSV.common.JSVFileManager.isURL(name)) {
var url =  new java.net.URL(Clazz.castNullAs("java.net.URL"), name, null);
return url.toString();
}return JSV.common.JSVFileManager.newFile(name).getFullPath();
}if (name.indexOf(":\\") == 1 || name.indexOf(":/") == 1) name = "file:///" + name;
 else if (name.startsWith("cache://")) return name;
var url =  new java.net.URL(JSV.common.JSVFileManager.appletDocumentBase, name, null);
return url.toString();
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
throw  new JSV.exception.JSVException("Cannot create path for " + name);
} else {
throw e;
}
}
}, "~S");
c$.isURL = Clazz.defineMethod(c$, "isURL", 
function(name){
for (var i = JSV.common.JSVFileManager.urlPrefixes.length; --i >= 0; ) if (name.startsWith(JSV.common.JSVFileManager.urlPrefixes[i])) return true;

return false;
}, "~S");
c$.urlTypeIndex = Clazz.defineMethod(c$, "urlTypeIndex", 
function(name){
for (var i = 0; i < JSV.common.JSVFileManager.urlPrefixes.length; ++i) {
if (name.startsWith(JSV.common.JSVFileManager.urlPrefixes[i])) {
return i;
}}
return -1;
}, "~S");
c$.isLocal = Clazz.defineMethod(c$, "isLocal", 
function(fileName){
if (fileName == null) return false;
var itype = JSV.common.JSVFileManager.urlTypeIndex(fileName);
return (itype < 0 || itype == 4);
}, "~S");
c$.getUnzippedBufferedReaderFromName = Clazz.defineMethod(c$, "getUnzippedBufferedReaderFromName", 
function(name){
var subFileList = null;
if (name.indexOf("|") >= 0) {
subFileList = JU.PT.split(name, "|");
if (subFileList != null && subFileList.length > 0) name = subFileList[0];
}if (name.startsWith("http://SIMULATION/")) return JSV.common.JSVFileManager.getSimulationReader(name);
try {
var ret = JSV.common.JSVFileManager.getInputStream(name, true, null);
if (Clazz.instanceOf(ret,"JU.SB") || (typeof(ret)=='string')) return  new java.io.BufferedReader( new java.io.StringReader(ret.toString()));
if (JSV.common.JSVFileManager.isAB(ret)) return  new java.io.BufferedReader( new java.io.StringReader( String.instantialize(ret)));
var bis =  new java.io.BufferedInputStream(ret);
var $in = bis;
if (JSV.common.JSVFileManager.isGzip(bis)) $in = (JSV.common.JSViewer.getInterface("JSV.common.JSVZipUtil")).newGZIPInputStream($in);
return  new java.io.BufferedReader( new java.io.InputStreamReader($in, "UTF-8"));
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
throw e;
} else {
throw e;
}
}
}, "~S");
c$.getAbbrSimulationFileName = Clazz.defineMethod(c$, "getAbbrSimulationFileName", 
function(name){
var type = JSV.common.JSVFileManager.getSimulationType(name);
var filename = JSV.common.JSVFileManager.getAbbreviatedSimulationName(name, type, true);
return filename;
}, "~S");
c$.getAbbreviatedSimulationName = Clazz.defineMethod(c$, "getAbbreviatedSimulationName", 
function(name, type, addProtocol){
return (name.indexOf("MOL=") >= 0 ? (addProtocol ? "http://SIMULATION/" : "") + "MOL=" + JSV.common.JSVFileManager.getSimulationHash(name, type) : name);
}, "~S,~S,~B");
c$.getSimulationHash = Clazz.defineMethod(c$, "getSimulationHash", 
function(name, type){
var code = type + Math.abs(name.substring(name.indexOf("V2000") + 1).hashCode());
if (JU.Logger.debugging) System.out.println("JSVFileManager hash for " + name + " = " + code);
return code;
}, "~S,~S");
c$.getSimulationFileData = Clazz.defineMethod(c$, "getSimulationFileData", 
function(name, type){
return JSV.common.JSVFileManager.cacheGet(name.startsWith("MOL=") ? name.substring(4) : JSV.common.JSVFileManager.getAbbreviatedSimulationName(name, type, false));
}, "~S,~S");
c$.cachePut = Clazz.defineMethod(c$, "cachePut", 
function(name, data){
if (JU.Logger.debugging) JU.Logger.debug("JSVFileManager cachePut " + data + " for " + name);
if (data != null) {
JSV.common.JSVFileManager.htCorrelationCache.put(name, data);
if (JSV.common.JSVFileManager.cacheDir != null && name.indexOf(":") < 0) {
try {
 new java.io.File(JSV.common.JSVFileManager.cacheDir).mkdirs();
var f =  new java.io.File(JSV.common.JSVFileManager.cacheDir, name);
var fos =  new java.io.FileOutputStream(f);
fos.write(data.getBytes());
fos.close();
} catch (e) {
if (Clazz.exceptionOf(e,"java.io.IOException")){
e.printStackTrace();
} else {
throw e;
}
}
}}}, "~S,~S");
c$.cacheGet = Clazz.defineMethod(c$, "cacheGet", 
function(key){
var data = JSV.common.JSVFileManager.htCorrelationCache.get(key);
if (JU.Logger.debugging) JU.Logger.info("JSVFileManager cacheGet " + data + " for " + key);
return data;
}, "~S");
c$.getSimulationReader = Clazz.defineMethod(c$, "getSimulationReader", 
function(name){
var data = JSV.common.JSVFileManager.cacheGet(name);
if (data == null) JSV.common.JSVFileManager.cachePut(name, data = JSV.common.JSVFileManager.getNMRSimulationJCampDX(name.substring("http://SIMULATION/".length)));
return JSV.common.JSVFileManager.getBufferedReaderForStringOrBytes(data);
}, "~S");
c$.isAB = Clazz.defineMethod(c$, "isAB", 
function(x){
return JU.AU.isAB(x);
}, "~O");
c$.isZipFile = Clazz.defineMethod(c$, "isZipFile", 
function(is){
try {
var abMagic =  Clazz.newByteArray (4, 0);
is.mark(5);
var countRead = is.read(abMagic, 0, 4);
is.reset();
return (countRead == 4 && abMagic[0] == 0x50 && abMagic[1] == 0x4B && abMagic[2] == 0x03 && abMagic[3] == 0x04);
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
throw  new JSV.exception.JSVException(e.toString());
} else {
throw e;
}
}
}, "java.io.InputStream");
c$.isGzip = Clazz.defineMethod(c$, "isGzip", 
function(is){
try {
var abMagic =  Clazz.newByteArray (4, 0);
is.mark(5);
var countRead = is.read(abMagic, 0, 4);
is.reset();
return (countRead == 4 && abMagic[0] == 0x1F && abMagic[1] == 0x8B);
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
throw  new JSV.exception.JSVException(e.toString());
} else {
throw e;
}
}
}, "java.io.InputStream");
c$.getStreamAsBytes = Clazz.defineMethod(c$, "getStreamAsBytes", 
function(bis, out){
try {
var buf =  Clazz.newByteArray (1024, 0);
var bytes = (out == null ?  Clazz.newByteArray (4096, 0) : null);
var len = 0;
var totalLen = 0;
while ((len = bis.read(buf, 0, 1024)) > 0) {
totalLen += len;
if (out == null) {
if (totalLen >= bytes.length) bytes = JU.AU.ensureLengthByte(bytes, totalLen * 2);
System.arraycopy(buf, 0, bytes, totalLen - len, len);
} else {
out.write(buf, 0, len);
}}
bis.close();
if (out == null) {
return JU.AU.arrayCopyByte(bytes, totalLen);
}return totalLen + " bytes";
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
throw  new JSV.exception.JSVException(e.toString());
} else {
throw e;
}
}
}, "java.io.BufferedInputStream,JU.OC");
c$.postByteArray = Clazz.defineMethod(c$, "postByteArray", 
function(fileName, bytes){
var ret = null;
try {
ret = JSV.common.JSVFileManager.getInputStream(fileName, false, bytes);
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
ret = e.toString();
} else {
throw e;
}
}
if ((typeof(ret)=='string')) return ret;
try {
ret = JSV.common.JSVFileManager.getStreamAsBytes(ret, null);
} catch (e) {
if (Clazz.exceptionOf(e,"JSV.exception.JSVException")){
try {
(ret).close();
} catch (e1) {
if (Clazz.exceptionOf(e1, Exception)){
} else {
throw e1;
}
}
} else {
throw e;
}
}
return (ret == null ? "" : JSV.common.JSVFileManager.fixUTF(ret));
}, "~S,~A");
c$.getUTFEncoding = Clazz.defineMethod(c$, "getUTFEncoding", 
function(bytes){
if (bytes.length >= 3 && bytes[0] == 0xEF && bytes[1] == 0xBB && bytes[2] == 0xBF) return JU.Encoding.UTF8;
if (bytes.length >= 4 && bytes[0] == 0 && bytes[1] == 0 && bytes[2] == 0xFE && bytes[3] == 0xFF) return JU.Encoding.UTF_32BE;
if (bytes.length >= 4 && bytes[0] == 0xFF && bytes[1] == 0xFE && bytes[2] == 0 && bytes[3] == 0) return JU.Encoding.UTF_32LE;
if (bytes.length >= 2 && bytes[0] == 0xFF && bytes[1] == 0xFE) return JU.Encoding.UTF_16LE;
if (bytes.length >= 2 && bytes[0] == 0xFE && bytes[1] == 0xFF) return JU.Encoding.UTF_16BE;
return JU.Encoding.NONE;
}, "~A");
c$.fixUTF = Clazz.defineMethod(c$, "fixUTF", 
function(bytes){
var encoding = JSV.common.JSVFileManager.getUTFEncoding(bytes);
if (encoding !== JU.Encoding.NONE) try {
var s =  String.instantialize(bytes, encoding.name().$replace('_', '-'));
switch (encoding) {
case JU.Encoding.UTF8:
case JU.Encoding.UTF_16BE:
case JU.Encoding.UTF_16LE:
s = s.substring(1);
break;
default:
break;
}
return s;
} catch (e) {
if (Clazz.exceptionOf(e,"java.io.IOException")){
JU.Logger.error("fixUTF error " + e);
} else {
throw e;
}
}
return  String.instantialize(bytes);
}, "~A");
c$.getInputStream = Clazz.defineMethod(c$, "getInputStream", 
function(name, showMsg, postBytes){
var isURL = JSV.common.JSVFileManager.isURL(name);
var isApplet = (JSV.common.JSVFileManager.appletDocumentBase != null);
var $in = null;
var post = null;
var iurl;
if (isURL && (iurl = name.indexOf("?POST?")) >= 0) {
post = name.substring(iurl + 6);
name = name.substring(0, iurl);
}if (isApplet || isURL) {
var url;
try {
url =  new java.net.URL(JSV.common.JSVFileManager.appletDocumentBase, name, null);
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
throw  new JSV.exception.JSVException("Cannot read " + name);
} else {
throw e;
}
}
JU.Logger.info("JSVFileManager opening URL " + url + (post == null ? "" : " with POST of " + post.length + " bytes"));
$in = JSV.common.JSVFileManager.viewer.apiPlatform.getURLContents(url, postBytes, post, false);
} else {
if (showMsg) JU.Logger.info("JSVFileManager opening file " + name);
$in = JSV.common.JSVFileManager.viewer.apiPlatform.getBufferedFileInputStream(name);
}if ((typeof($in)=='string')) throw  new JSV.exception.JSVException("\n" + $in);
return $in;
}, "~S,~B,~A");
c$.getNMRSimulationJCampDX = Clazz.defineMethod(c$, "getNMRSimulationJCampDX", 
function(name){
var pt = 0;
var molFile = null;
var type = JSV.common.JSVFileManager.getSimulationType(name);
if (name.startsWith(type)) name = name.substring(type.length + 1);
var isInline = name.startsWith("MOL=");
if (isInline) {
name = name.substring(4);
pt = name.indexOf("/n__Jmol");
if (pt > 0) name = name.substring(0, pt) + JU.PT.rep(name.substring(pt), "/n", "\n");
molFile = name = JU.PT.rep(name, "\\n", "\n");
}var key = "" + JSV.common.JSVFileManager.getSimulationHash(name, type);
if (JU.Logger.debugging) JU.Logger.info("JSVFileManager type=" + type + " key=" + key + " name=" + name);
var jcamp = JSV.common.JSVFileManager.cacheGet(key);
if (jcamp != null) return jcamp;
var src = (isInline ? null : JU.PT.rep(JSV.common.JSVFileManager.nciResolver, "%FILE", JU.PT.escapeUrl(name)));
if (!isInline && (molFile = JSV.common.JSVFileManager.getFileAsString(src)) == null || molFile.indexOf("<html") >= 0) {
JU.Logger.error("no MOL data returned by NCI");
return null;
}var is13C = type.equals("C13");
var url;
url = (is13C ? JSV.common.JSVFileManager.nmrdbServerC13 : JSV.common.JSVFileManager.nmrdbServerH1);
url = url.$replace("$MOLFILE", molFile);
JSV.common.JSVFileManager.cachePut("url", url);
var json = JSV.common.JSVFileManager.getFileAsString(url);
if ((json == null ? (json = "Error: Error fetching simulation") : json).indexOf("Error:") >= 0) {
return json;
}var map = ( new JU.JSJSONParser()).parseMap(json, true);
JSV.common.JSVFileManager.cachePut("json", json);
return JSV.common.JSVFileManager.processJSON(key, src, url, name, type, molFile, map, is13C, isInline);
}, "~S");
c$.processJSON = Clazz.defineMethod(c$, "processJSON", 
function(key, src, url, name, type, molFile, json, is13C, isInline){
var map = json.get("data");
var jsonMolFile = map.get("molfile");
if (jsonMolFile == null) {
System.out.println("JSVFileManager: no MOL file returned from EPFL");
jsonMolFile = molFile;
}var atomMap = JSV.common.JSVFileManager.getAtomMap(jsonMolFile, molFile);
JSV.common.JSVFileManager.cachePut("mol", molFile);
var bytes = (isInline || !JSV.common.JSViewer.isJS ? null : molFile.getBytes());
{
if (bytes) Jmol.Cache.put("http://SIMULATION/" + type +
"/" + name + "#molfile", bytes);
}var xml = "<Signals src=" + JU.PT.esc(url.substring(0, url.indexOf('?'))) + ">\n";
var jcamp;
type = (is13C ? "13C" : "1HNMR");
jcamp = map.get("jcamp");
jcamp = JSV.common.JSVFileManager.hackNewNmriumSimulationJCAMP(jcamp);
var signals = map.get("signals");
var bf1 = JSV.common.JSVFileManager.getval(jcamp, "##$BF1");
var freq = (bf1 == null ? (is13C ? 100 : 400) : Float.parseFloat(bf1));
var sb =  new JU.SB();
for (var i = signals.size(); --i >= 0; ) {
var signal = signals.get(i);
sb.append("<Signal ");
JSV.common.JSVFileManager.setAttr(sb, "type", type, null);
var index = (signal.get("atoms")).get(0);
if (atomMap == null) {
JSV.common.JSVFileManager.setAttr(sb, "atoms", index, null);
} else {
sb.append("atoms=\"").appendI(atomMap[index.intValue()]).append("\" ");
}JSV.common.JSVFileManager.setAttr(sb, "multiplicity", "multiplicity", signal);
var delta = signal.get("delta");
var minmax = JSV.common.JSVFileManager.getSignalMinMax(signal, delta.floatValue(), freq, is13C);
JSV.common.JSVFileManager.setAttr(sb, "xMin", "" + minmax[0], null);
JSV.common.JSVFileManager.setAttr(sb, "xMax", "" + minmax[1], null);
JSV.common.JSVFileManager.setAttr(sb, "integral", "nbAtoms", signal);
sb.append("></Signal>\n");
}
sb.append("</Signals>");
xml += sb.toString();
if (JU.Logger.debugging) JU.Logger.info(xml);
JSV.common.JSVFileManager.cachePut("xml", xml);
jcamp = "##TITLE=" + (isInline ? "JMOL SIMULATION/" + type : name) + "\n" + jcamp.substring(jcamp.indexOf("\n##") + 1);
var pt = molFile.indexOf("\n");
pt = molFile.indexOf("\n", pt + 1);
if (pt > 0 && pt == molFile.indexOf("\n \n")) molFile = molFile.substring(0, pt + 1) + "Created " + JSV.common.JSVFileManager.viewer.apiPlatform.getDateFormat("8824") + " by JSpecView " + JSV.common.JSVersion.VERSION + molFile.substring(pt + 1);
pt = 0;
pt = jcamp.indexOf("##.");
var id = JSV.common.JSVFileManager.getAbbreviatedSimulationName(name, type, false);
var pt1 = id.indexOf("id='");
if (isInline && pt1 > 0) id = id.substring(pt1 + 4, (id + "'").indexOf("'", pt1 + 4));
JSV.common.JSVFileManager.cachePut(type + "-json.jcamp", jcamp);
jcamp = jcamp.substring(0, pt) + "##$MODELS=\n<Models>\n" + "<ModelData id=" + JU.PT.esc(id) + " type=\"MOL\" src=" + JU.PT.esc(src) + ">\n" + molFile + "</ModelData>\n</Models>\n" + "##$SIGNALS=\n" + xml + "\n" + jcamp.substring(pt);
JSV.common.JSVFileManager.cachePut("jcamp", jcamp);
JSV.common.JSVFileManager.cachePut(key, jcamp);
return jcamp;
}, "~S,~S,~S,~S,~S,~S,java.util.Map,~B,~B");
c$.getSignalMinMax = Clazz.defineMethod(c$, "getSignalMinMax", 
function(signal, delta, freq, is13C){
var minmax =  Clazz.newFloatArray (2, 0);
if (is13C) {
minmax[0] = delta - 0.5;
minmax[1] = delta + 0.5;
} else {
var js = signal.get("js");
var d = 1;
for (var i = js.size(); --i >= 0; ) {
var j = js.get(i);
var c = (j.get("coupling")).floatValue();
switch (j.get("multiplicity")) {
case "d":
d += c / 2;
break;
default:
case "t":
d += c;
break;
}
}
minmax[0] = delta - d / freq;
minmax[1] = delta + d / freq;
}return minmax;
}, "java.util.Map,~N,~N,~B");
c$.hackNewNmriumSimulationJCAMP = Clazz.defineMethod(c$, "hackNewNmriumSimulationJCAMP", 
function(jcamp){
var shift = JSV.common.JSVFileManager.getline(jcamp, "##.SHIFT REFERENCE=INTERNAL");
var offset = JSV.common.JSVFileManager.getval(jcamp, "##$OFFSET");
if (shift != null && offset != null) {
shift = shift.substring(0, shift.lastIndexOf(", ") + 2) + offset;
jcamp = JSV.common.JSVFileManager.setline(jcamp, "##$OFFSET", null);
jcamp = JSV.common.JSVFileManager.setline(jcamp, "##.SHIFT REFERENCE=INTERNAL", shift);
}return jcamp;
}, "~S");
c$.getline = Clazz.defineMethod(c$, "getline", 
function(jcamp, record){
var pt = jcamp.indexOf(record);
if (pt < 0) return null;
return jcamp.substring(pt, jcamp.indexOf("\n", pt));
}, "~S,~S");
c$.getval = Clazz.defineMethod(c$, "getval", 
function(jcamp, record){
var pt = jcamp.indexOf(record);
if (pt < 0) return null;
var val = jcamp.substring(jcamp.indexOf("=", pt) + 1, jcamp.indexOf("\n", pt));
pt = val.indexOf("$$");
if (pt >= 0) val = val.substring(pt);
return val.trim();
}, "~S,~S");
c$.setline = Clazz.defineMethod(c$, "setline", 
function(jcamp, record, replacement){
var pt = jcamp.indexOf(record);
if (pt < 0) return jcamp;
if (replacement == null) return jcamp.substring(0, pt) + jcamp.substring(jcamp.indexOf("\n", pt) + 1);
return jcamp.substring(0, pt) + replacement + jcamp.substring(jcamp.indexOf("\n", pt));
}, "~S,~S,~S");
c$.getAtomMap = Clazz.defineMethod(c$, "getAtomMap", 
function(jsonMolFile, jmolMolFile){
var acJson = JSV.common.JSVFileManager.getCoord(jsonMolFile);
var acJmol = JSV.common.JSVFileManager.getCoord(jmolMolFile);
var n = acJson.length;
if (n != acJmol.length) return null;
var map =  Clazz.newIntArray (n, 0);
var bs =  new JU.BS();
bs.setBits(0, n);
var haveMap = false;
for (var i = 0; i < n; i++) {
var a = acJson[i];
for (var j = bs.nextSetBit(0); j >= 0; j = bs.nextSetBit(j + 1)) {
if (a.distanceSquared(acJmol[j]) < 0.1) {
bs.clear(j);
map[i] = j;
if (i != j) haveMap = true;
break;
}}
}
return (haveMap ? map : null);
}, "~S,~S");
c$.getCoord = Clazz.defineMethod(c$, "getCoord", 
function(mol){
var lines = JU.PT.split(mol, "\n");
var data =  Clazz.newFloatArray (3, 0);
var n = Integer.parseInt(lines[3].substring(0, 3).trim());
var pts =  new Array(n);
for (var i = 0; i < n; i++) {
var line = lines[4 + i];
JU.PT.parseFloatArrayInfested(JU.PT.getTokens(line.substring(0, 31)), data);
pts[i] = JU.P3.new3(data[0], data[1], data[2]);
}
return pts;
}, "~S");
c$.setAttr = Clazz.defineMethod(c$, "setAttr", 
function(sb, mykey, lucsKeyOrVal, map){
sb.append(mykey + "=\"").appendO((map == null ? lucsKeyOrVal : map.get(lucsKeyOrVal))).append("\" ");
}, "JU.SB,~S,~O,java.util.Map");
c$.getResource = Clazz.defineMethod(c$, "getResource", 
function(object, fileName, error){
var url = null;
try {
if ((url = object.getClass().getResource(fileName)) == null) error[0] = "Couldn't find file: " + fileName;
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
error[0] = "Exception " + e + " in getResource " + fileName;
} else {
throw e;
}
}
return url;
}, "~O,~S,~A");
c$.getResourceString = Clazz.defineMethod(c$, "getResourceString", 
function(object, name, error){
var url = JSV.common.JSVFileManager.getResource(object, name, error);
if (url == null) {
error[0] = "Error loading resource " + name;
return null;
}if ((typeof(url)=='string')) {
return JSV.common.JSVFileManager.getFileAsString(url);
}var sb =  new JU.SB();
try {
var br =  new java.io.BufferedReader( new java.io.InputStreamReader((url).getContent(), "UTF-8"));
var line;
while ((line = br.readLine()) != null) sb.append(line).append("\n");

br.close();
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
error[0] = e.toString();
} else {
throw e;
}
}
return sb.toString();
}, "~O,~S,~A");
c$.getJmolFilePath = Clazz.defineMethod(c$, "getJmolFilePath", 
function(filePath){
try {
filePath = JSV.common.JSVFileManager.getFullPathName(filePath);
} catch (e) {
if (Clazz.exceptionOf(e,"JSV.exception.JSVException")){
return null;
} else {
throw e;
}
}
return (JSV.common.JSVFileManager.appletDocumentBase == null ? filePath.$replace('\\', '/') : filePath);
}, "~S");
c$.getTagName = Clazz.defineMethod(c$, "getTagName", 
function(fileName){
if (fileName == null) return "String" + (++JSV.common.JSVFileManager.stringCount);
if (JSV.common.JSVFileManager.isURL(fileName)) {
try {
if (fileName.startsWith("http://SIMULATION/")) return JSV.common.JSVFileManager.getAbbrSimulationFileName(fileName);
var name = ( new java.net.URL(Clazz.castNullAs("java.net.URL"), fileName, null)).getFile();
return name.substring(name.lastIndexOf('/') + 1);
} catch (e) {
if (Clazz.exceptionOf(e,"java.io.IOException")){
return null;
} else {
throw e;
}
}
}return JSV.common.JSVFileManager.newFile(fileName).getName();
}, "~S");
c$.newFile = Clazz.defineMethod(c$, "newFile", 
function(fileName){
return JSV.common.JSVFileManager.viewer.apiPlatform.newFile(fileName);
}, "~S");
c$.setDocumentBase = Clazz.defineMethod(c$, "setDocumentBase", 
function(v, documentBase){
JSV.common.JSVFileManager.viewer = v;
JSV.common.JSVFileManager.appletDocumentBase = documentBase;
}, "JSV.common.JSViewer,java.net.URL");
c$.getSimulationType = Clazz.defineMethod(c$, "getSimulationType", 
function(filePath){
return (filePath.indexOf("C13/") >= 0 ? "C13" : "H1");
}, "~S");
c$.appletDocumentBase = null;
c$.viewer = null;
c$.jsDocumentBase = "";
c$.urlPrefixes =  Clazz.newArray(-1, ["http:", "https:", "ftp:", "http://SIMULATION/", "file:"]);
c$.htCorrelationCache =  new java.util.Hashtable();
c$.cacheDir = null;
c$.nciResolver = "https://cactus.nci.nih.gov/chemical/structure/%FILE/file?format=sdf&get3d=True";
c$.nmrdbServerH1 = "https://nmr-prediction.service.zakodium.com/v1/predict/proton?POST?{\"molfile\":\"$MOLFILE\",\"includeJDX\":true}";
c$.nmrdbServerC13 = "https://nmr-prediction.service.zakodium.com/v1/predict/carbon?POST?{\"molfile\":\"$MOLFILE\",\"includeJDX\":true}";
c$.stringCount = 0;
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
