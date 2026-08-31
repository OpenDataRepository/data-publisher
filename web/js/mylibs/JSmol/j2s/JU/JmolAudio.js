Clazz.declarePackage("JU");
Clazz.load(["J.api.JmolAudioPlayer"], "JU.JmolAudio", ["JU.Logger", "JV.Viewer"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.params = null;
this.myClip = null;
this.fileName = null;
this.vwr = null;
this.id = null;
this.autoClose = false;
Clazz.instantialize(this, arguments);}, JU, "JmolAudio", null, [J.api.JmolAudioPlayer]);
/*LV!1824 unnec constructor*/Clazz.defineMethod(c$, "playAudio", 
function(vwr, htParams){
try {
this.id = htParams.get("id");
if (this.id == null || this.id.length == 0) {
this.autoClose = true;
htParams.put("id", this.id = "audio" + ++JU.JmolAudio.idCount);
}this.vwr = vwr;
this.params = htParams;
this.params.put("audioPlayer", this);
this.fileName = htParams.get("audioFile");
vwr.sm.registerAudio(this.id, htParams);
var applet = vwr.html5Applet;
var jmol = JV.Viewer.jmolObject;
if (jmol == null) this.getClip();
 else jmol.playAudio(applet, htParams);
if (this.myClip == null) return;
if (htParams.containsKey("action")) this.action(htParams.get("action"));
 else if (htParams.containsKey("loop")) {
this.action("loop");
} else {
this.autoClose = true;
this.action("start");
}} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
JU.Logger.info("File " + this.fileName + " could not be opened as an audio file");
} else {
throw e;
}
}
}, "JV.Viewer,java.util.Map");
Clazz.overrideMethod(c$, "update", 
function(le){
}, "javax.sound.sampled.LineEvent");
Clazz.overrideMethod(c$, "processUpdate", 
function(type){
JU.Logger.info("audio id " + this.id + " " + this.fileName + " " + type);
var status = null;
switch (type) {
case "open":
case "Open":
status = "open";
break;
case "play":
case "Start":
status = "play";
break;
case "pause":
case "Stop":
status = "pause";
break;
case "ended":
case "Close":
status = "ended";
break;
default:
status = type;
break;
}
if (!status.equals(this.params.get("status"))) {
this.params.put("statusType", type);
this.params.put("status", status);
this.vwr.sm.notifyAudioStatus(this.params);
if (status === "ended" && this.autoClose) {
this.myClip.close();
}}}, "~S");
Clazz.overrideMethod(c$, "action", 
function(action){
if (this.myClip == null) {
if (action === "kill") return;
this.params.put("status", "ended");
this.vwr.sm.notifyAudioStatus(this.params);
return;
}try {
switch (action) {
case "start":
this.myClip.setMicrosecondPosition(0);
this.myClip.loop(0);
this.myClip.start();
break;
case "loop":
this.myClip.setMicrosecondPosition(0);
this.myClip.loop(10);
this.myClip.start();
break;
case "pause":
this.myClip.stop();
break;
case "stop":
this.myClip.stop();
this.myClip.setMicrosecondPosition(0);
break;
case "play":
this.myClip.stop();
this.myClip.start();
break;
case "kill":
case "close":
this.myClip.stop();
this.myClip.close();
break;
}
} catch (t) {
}
}, "~S");
c$.idCount = 0;
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
