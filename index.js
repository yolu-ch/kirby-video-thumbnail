(function() {
  "use strict";
  function normalizeComponent(scriptExports, render, staticRenderFns, functionalTemplate, injectStyles, scopeId, moduleIdentifier, shadowMode) {
    var options = typeof scriptExports === "function" ? scriptExports.options : scriptExports;
    if (render) {
      options.render = render;
      options.staticRenderFns = staticRenderFns;
      options._compiled = true;
    }
    return {
      exports: scriptExports,
      options
    };
  }
  const _sfc_main$1 = {
    props: {
      back: String,
      color: String,
      cover: { type: Boolean, default: true },
      icon: String,
      type: String,
      url: String
    },
    data() {
      return { hasFrame: false };
    },
    computed: {
      isVideo() {
        var _a;
        return (_a = this.type) == null ? void 0 : _a.startsWith("video/");
      },
      isPreviewable() {
        return [
          "image/jpeg",
          "image/jpg",
          "image/gif",
          "image/png",
          "image/webp",
          "image/avif",
          "image/svg+xml"
        ].includes(this.type);
      },
      fallbackColor() {
        var _a, _b, _c;
        if ((_a = this.type) == null ? void 0 : _a.startsWith("image/")) return "orange-500";
        if ((_b = this.type) == null ? void 0 : _b.startsWith("audio/")) return "aqua-500";
        if ((_c = this.type) == null ? void 0 : _c.startsWith("video/")) return "yellow-500";
        return "white";
      },
      fallbackIcon() {
        var _a, _b, _c;
        if ((_a = this.type) == null ? void 0 : _a.startsWith("image/")) return "image";
        if ((_b = this.type) == null ? void 0 : _b.startsWith("audio/")) return "audio";
        if ((_c = this.type) == null ? void 0 : _c.startsWith("video/")) return "video";
        return "file";
      }
    },
    mounted() {
      if (this.isVideo && this.url) this.captureFrame(this.url);
      window.addEventListener("vt:seek-preview", this.onSeekPreview);
    },
    beforeUnmount() {
      window.removeEventListener("vt:seek-preview", this.onSeekPreview);
      if (this.videoEl) {
        this.videoEl.src = "";
        this.videoEl = null;
      }
    },
    watch: {
      url(newUrl) {
        if (this.isVideo && newUrl) {
          this.hasFrame = false;
          this.captureFrame(newUrl);
        }
      }
    },
    methods: {
      captureFrame(url) {
        if (this.videoEl) this.videoEl.src = "";
        const video = document.createElement("video");
        video.muted = true;
        video.playsInline = true;
        video.preload = "auto";
        this.videoEl = video;
        video.addEventListener("loadedmetadata", () => {
          const duration = isFinite(video.duration) ? video.duration : 0;
          window.dispatchEvent(new CustomEvent("vt:duration-ready", {
            detail: { videoUrl: url, duration }
          }));
          video.addEventListener("seeked", () => this.drawFrame(), { once: true });
          video.currentTime = Math.min(0.5, duration);
        }, { once: true });
        video.addEventListener("error", () => {
        }, { once: true });
        video.src = url;
        video.load();
      },
      onSeekPreview({ detail: { videoUrl, time } }) {
        if (videoUrl !== this.url || !this.videoEl) return;
        this._pendingSeek = time;
        if (!this._seeking) this._doNextSeek();
      },
      _doNextSeek() {
        if (this._pendingSeek == null || !this.videoEl) return;
        const time = this._pendingSeek;
        this._pendingSeek = null;
        this._seeking = true;
        this.videoEl.addEventListener("seeked", () => {
          this._seeking = false;
          this.drawFrame();
          if (this._pendingSeek != null) this._doNextSeek();
        }, { once: true });
        this.videoEl.currentTime = time;
      },
      drawFrame() {
        const canvas = this.$refs.canvasEl;
        if (!canvas || !this.videoEl) return;
        canvas.width = this.videoEl.videoWidth || 320;
        canvas.height = this.videoEl.videoHeight || 180;
        canvas.getContext("2d").drawImage(this.videoEl, 0, 0);
        this.hasFrame = true;
      }
    }
  };
  var _sfc_render$1 = function render() {
    var _vm = this, _c = _vm._self._c;
    return !_vm.isVideo ? _c("a", { staticClass: "k-upload-item-preview", attrs: { "href": _vm.url, "target": "_blank" } }, [_vm.isPreviewable ? _c("k-image", { attrs: { "cover": _vm.cover, "src": _vm.url, "back": _vm.back ?? "pattern" } }) : _c("k-icon-frame", { attrs: { "color": _vm.color ?? _vm.fallbackColor, "icon": _vm.icon ?? _vm.fallbackIcon, "back": _vm.back ?? "black", "ratio": "1/1" } })], 1) : _c("div", { staticClass: "k-upload-item-preview k-upload-item-preview--video" }, [_c("canvas", { directives: [{ name: "show", rawName: "v-show", value: _vm.hasFrame, expression: "hasFrame" }], ref: "canvasEl", staticClass: "k-upload-item-canvas" }), _c("k-icon-frame", { directives: [{ name: "show", rawName: "v-show", value: !_vm.hasFrame, expression: "!hasFrame" }], attrs: { "color": "yellow-500", "icon": "video", "back": "black", "ratio": "16/9" } })], 1);
  };
  var _sfc_staticRenderFns$1 = [];
  _sfc_render$1._withStripped = true;
  var __component__$1 = /* @__PURE__ */ normalizeComponent(
    _sfc_main$1,
    _sfc_render$1,
    _sfc_staticRenderFns$1
  );
  __component__$1.options.__file = "/Users/jonathan/Sites/git/floorball.beee.live/site/plugins/video-thumbnail/src/components/VideoUploadItemPreview.vue";
  const VideoUploadItemPreview = __component__$1.exports;
  const _sfc_main = {
    props: {
      items: Array
    },
    emits: ["remove", "rename"],
    data() {
      return {
        thumbMap: /* @__PURE__ */ new Map(),
        durations: {},
        seekTimes: {}
      };
    },
    computed: {
      visibleItems() {
        var _a;
        return ((_a = this.items) == null ? void 0 : _a.filter((item) => {
          var _a2;
          return !((_a2 = item.filename) == null ? void 0 : _a2.endsWith("_thumb.jpg"));
        })) ?? [];
      }
    },
    mounted() {
      this.processAdditions(this.items, []);
      window.addEventListener("vt:duration-ready", this.onDurationReady);
      window.addEventListener("video-thumbnail-update", this.handleThumbnailUpdate);
    },
    unmounted() {
      window.removeEventListener("vt:duration-ready", this.onDurationReady);
      window.removeEventListener("video-thumbnail-update", this.handleThumbnailUpdate);
    },
    watch: {
      items(newItems, oldItems) {
        this.processAdditions(newItems, oldItems);
        this.processRemovals(newItems, oldItems);
      }
    },
    methods: {
      onDurationReady({ detail: { videoUrl, duration } }) {
        this.durations[videoUrl] = duration;
        if (this.seekTimes[videoUrl] == null) {
          this.seekTimes[videoUrl] = Math.min(0, duration);
        }
      },
      onScrub(videoUrl, time) {
        this.seekTimes[videoUrl] = time;
        window.dispatchEvent(new CustomEvent("vt:seek-preview", {
          detail: { videoUrl, time }
        }));
      },
      onScrubEnd(videoUrl) {
        const time = this.seekTimes[videoUrl] ?? 0.5;
        this.captureBlob(videoUrl, time).then((blob) => {
          if (blob) this.updateThumb(videoUrl, blob);
        });
      },
      processAdditions(newItems, oldItems) {
        newItems == null ? void 0 : newItems.forEach((item) => {
          var _a;
          if (((_a = item.type) == null ? void 0 : _a.startsWith("video/")) && !this.thumbMap.has(item.url)) {
            this.thumbMap.set(item.url, null);
            this.addThumbnail(item);
          }
        });
      },
      processRemovals(newItems, oldItems) {
        oldItems == null ? void 0 : oldItems.forEach((item) => {
          var _a;
          if (!((_a = item.type) == null ? void 0 : _a.startsWith("video/"))) return;
          if (newItems == null ? void 0 : newItems.some((i) => i.id === item.id)) return;
          const thumbId = this.thumbMap.get(item.url);
          if (thumbId) this.$panel.upload.remove(thumbId);
          this.thumbMap.delete(item.url);
          delete this.durations[item.url];
          delete this.seekTimes[item.url];
        });
      },
      async addThumbnail(videoItem) {
        const blob = await this.captureBlob(videoItem.url, 0.5);
        if (!blob) return;
        const name = videoItem.name + "_thumb";
        const filename = name + ".jpg";
        const file = new File([blob], filename, { type: "image/jpeg" });
        const id = Date.now().toString(36) + Math.random().toString(36).slice(2);
        const thumbItem = {
          completed: false,
          error: null,
          extension: "jpg",
          filename,
          id,
          model: null,
          name,
          niceSize: this.formatSize(file.size),
          progress: 0,
          size: file.size,
          src: file,
          type: "image/jpeg",
          url: URL.createObjectURL(file)
        };
        this.thumbMap.set(videoItem.url, id);
        this.$panel.upload.files = [...this.$panel.upload.files, thumbItem];
      },
      captureBlob(url, time) {
        return new Promise((resolve) => {
          const video = document.createElement("video");
          video.muted = true;
          video.playsInline = true;
          video.preload = "auto";
          let done = false;
          const draw = () => {
            if (done) return;
            done = true;
            const canvas = document.createElement("canvas");
            canvas.width = video.videoWidth || 320;
            canvas.height = video.videoHeight || 180;
            canvas.getContext("2d").drawImage(video, 0, 0);
            video.src = "";
            canvas.toBlob((blob) => resolve(blob), "image/jpeg", 0.85);
          };
          video.addEventListener("seeked", draw, { once: true });
          video.addEventListener("loadedmetadata", () => {
            video.currentTime = Math.min(time, video.duration);
          }, { once: true });
          video.addEventListener("error", () => {
            if (!done) {
              done = true;
              resolve(null);
            }
          }, { once: true });
          video.src = url;
          video.load();
        });
      },
      updateThumb(videoUrl, blob) {
        var _a;
        const thumbId = this.thumbMap.get(videoUrl);
        if (!thumbId) return;
        const files = this.$panel.upload.files;
        const idx = files.findIndex((f) => f.id === thumbId);
        if (idx === -1) return;
        const old = files[idx];
        const file = new File([blob], old.filename, { type: "image/jpeg" });
        if ((_a = old.url) == null ? void 0 : _a.startsWith("blob:")) URL.revokeObjectURL(old.url);
        const url = URL.createObjectURL(file);
        const newFiles = [...files];
        newFiles[idx] = { ...old, src: file, url, size: file.size, niceSize: this.formatSize(file.size) };
        this.$panel.upload.files = newFiles;
      },
      handleThumbnailUpdate({ detail: { videoUrl, blob } }) {
        this.updateThumb(videoUrl, blob);
      },
      formatSize(bytes) {
        if (bytes < 1024) return bytes + " B";
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + " KB";
        return (bytes / 1048576).toFixed(1) + " MB";
      }
    }
  };
  var _sfc_render = function render() {
    var _vm = this, _c = _vm._self._c;
    return _c("ul", { staticClass: "k-upload-items" }, [_vm._l(_vm.visibleItems, function(item) {
      var _a;
      return [_c("k-upload-item", _vm._b({ key: item.id, staticClass: "k-upload-item-video", on: { "rename": function($event) {
        return _vm.$emit("rename", item, $event);
      }, "remove": function($event) {
        return _vm.$emit("remove", item);
      } } }, "k-upload-item", item, false)), ((_a = item.type) == null ? void 0 : _a.startsWith("video/")) && _vm.durations[item.url] ? _c("li", { key: item.id + "-slider", staticClass: "vt-slider-row", on: { "pointerup": function($event) {
        return _vm.onScrubEnd(item.url);
      } } }, [_c("k-range-field", { attrs: { "min": 0, "max": _vm.durations[item.url], "step": 0.01, "value": _vm.seekTimes[item.url] ?? 0, "help": _vm.$t("video-thumbnail.slider.help") }, on: { "input": function($event) {
        return _vm.onScrub(item.url, $event);
      } } })], 1) : _vm._e()];
    })], 2);
  };
  var _sfc_staticRenderFns = [];
  _sfc_render._withStripped = true;
  var __component__ = /* @__PURE__ */ normalizeComponent(
    _sfc_main,
    _sfc_render,
    _sfc_staticRenderFns
  );
  __component__.options.__file = "/Users/jonathan/Sites/git/floorball.beee.live/site/plugins/video-thumbnail/src/components/VideoUploadItems.vue";
  const VideoUploadItems = __component__.exports;
  panel.plugin("yolu/video-thumbnail", {
    components: {
      "k-upload-item-preview": VideoUploadItemPreview,
      "k-upload-items": VideoUploadItems
    }
  });
})();
