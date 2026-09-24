import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';

// File field behind x-form.image: pick or drop a file; an image opens the cropper (whole image or a crop,
// any ratio or a fixed one, rotate, zoom) and the result is uploaded to the Livewire property `name`.
// Anything else (a PDF) uploads as it is.
export default ({ name, aspect = null, maxSide = 2400 }) => ({
    name,
    fixedAspect: aspect,
    ratio: aspect ?? NaN,
    mode: 'crop',
    editing: false,
    dragging: false,
    uploading: false,
    progress: 0,
    preview: null,
    fileName: '',
    fileSize: '',
    isImage: false,
    source: null,
    cropper: null,
    error: '',

    choose() {
        this.$refs.input.click();
    },
    picked(event) {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (file) {
            this.take(file);
        }
    },
    dropped(event) {
        this.dragging = false;
        const file = event.dataTransfer?.files?.[0];
        if (file) {
            this.take(file);
        }
    },
    take(file) {
        this.error = '';
        this.source = file;
        if (!file.type.startsWith('image/') || file.type === 'image/gif') {
            this.isImage = false;
            this.upload(file);

            return;
        }
        this.isImage = true;
        this.mode = 'crop';
        this.ratio = this.fixedAspect ?? NaN;
        this.editing = true;
        this.$nextTick(() => {
            this.$refs.image.src = URL.createObjectURL(file);
            this.$refs.image.onload = () => this.startCropper();
        });
    },
    startCropper() {
        this.cropper?.destroy();
        this.cropper = new Cropper(this.$refs.image, {
            viewMode: 1,
            aspectRatio: this.ratio,
            autoCropArea: 1,
            background: false,
            responsive: true,
            checkOrientation: true,
        });
    },
    setMode(mode) {
        this.mode = mode;
        if (mode === 'whole') {
            this.cropper?.clear();
        } else {
            this.cropper?.crop();
            this.cropper?.setAspectRatio(this.ratio);
        }
    },
    setRatio(value) {
        this.ratio = value;
        this.setMode('crop');
    },
    rotate(degrees) {
        this.cropper?.rotate(degrees);
    },
    zoom(step) {
        this.cropper?.zoom(step);
    },
    cancelEdit() {
        this.editing = false;
        this.cropper?.destroy();
        this.cropper = null;
    },
    apply() {
        if (!this.cropper) {
            return;
        }
        const type = ['image/png', 'image/webp'].includes(this.source.type) ? this.source.type : 'image/jpeg';
        const options = { maxWidth: maxSide, maxHeight: maxSide, imageSmoothingQuality: 'high', fillColor: type === 'image/jpeg' ? '#fff' : 'transparent' };
        if (this.mode === 'whole') {
            // The crop box stretched over the displayed canvas, so the whole (rotated) image is kept.
            this.cropper.setAspectRatio(NaN);
            this.cropper.crop();
            const { left, top, width, height } = this.cropper.getCanvasData();
            this.cropper.setCropBoxData({ left, top, width, height });
        }
        this.cropper.getCroppedCanvas(options).toBlob((blob) => {
            const extension = { 'image/png': 'png', 'image/webp': 'webp' }[type] ?? 'jpg';
            const base = this.source.name.replace(/\.[^.]+$/, '') || 'image';
            this.cancelEdit();
            this.upload(new File([blob], `${base}.${extension}`, { type }));
        }, type, 0.9);
    },
    upload(file) {
        this.uploading = true;
        this.progress = 0;
        this.fileName = file.name;
        this.fileSize = file.size > 1048576 ? `${(file.size / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(file.size / 1024))} KB`;
        if (this.preview) {
            URL.revokeObjectURL(this.preview);
        }
        this.preview = file.type.startsWith('image/') ? URL.createObjectURL(file) : null;
        this.$wire.upload(this.name, file,
            () => { this.uploading = false; },
            () => { this.uploading = false; this.error = this.$root.dataset.failed; this.clearLocal(); },
            (event) => { this.progress = event.detail.progress; });
    },
    remove() {
        this.clearLocal();
        this.$wire.set(this.name, null);
    },
    clearLocal() {
        if (this.preview) {
            URL.revokeObjectURL(this.preview);
        }
        this.preview = null;
        this.fileName = '';
        this.fileSize = '';
    },
    destroy() {
        this.cropper?.destroy();
        if (this.preview) {
            URL.revokeObjectURL(this.preview);
        }
    },
});
