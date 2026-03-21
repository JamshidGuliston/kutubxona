<div style="padding: 24px; text-align: center;">
    <div style="
        background: linear-gradient(135deg, #1e293b, #334155);
        border-radius: 16px;
        padding: 32px 24px;
        color: white;
        box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    ">
        <div style="font-size: 48px; margin-bottom: 16px;">🎵</div>
        <h3 style="margin: 0 0 8px; font-size: 1.1rem; font-weight: 600; color: #f1f5f9;">
            <?php echo e($title); ?>

        </h3>
        <p style="margin: 0 0 24px; font-size: 0.8rem; color: #94a3b8;">Audio fayl</p>

        <audio
            controls
            style="width: 100%; border-radius: 8px; outline: none;"
            preload="metadata"
        >
            <source src="<?php echo e($url); ?>" type="audio/mpeg">
            <source src="<?php echo e($url); ?>" type="audio/mp4">
            <source src="<?php echo e($url); ?>" type="audio/ogg">
            Brauzeringiz audio faylni qo'llab-quvvatlamaydi.
        </audio>
    </div>
</div>
<?php /**PATH D:\OSPanel\home\kutubxona.uz\resources\views/filament/modals/audio-player.blade.php ENDPATH**/ ?>