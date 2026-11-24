import os
import subprocess
import wave
import math
import struct

def generate_ambient_audio(output_wav, duration_sec=16, sample_rate=44100):
    """
    Generate an upbeat, elegant tech soundtrack with soft chord progression and beat pulses
    """
    n_samples = int(duration_sec * sample_rate)
    wav_file = wave.open(output_wav, 'w')
    wav_file.setparams((2, 2, sample_rate, n_samples, 'NONE', 'not compressed'))
    
    # Musical chord frequencies (D minor / F major inspiring progression)
    # Chord 1: D4, F4, A4 (0 to 4s)
    # Chord 2: Bb3, D4, F4 (4 to 8s)
    # Chord 3: G3, Bb3, D4 (8 to 12s)
    # Chord 4: A3, C#4, E4 (12 to 16s)
    chords = [
        [293.66, 349.23, 440.00], # Dm
        [233.08, 293.66, 349.23], # Bb
        [196.00, 233.08, 293.66], # Gm
        [220.00, 277.18, 329.63], # A maj
    ]
    
    frames = bytearray()
    bpm = 120
    beat_interval = 60.0 / bpm # 0.5s per beat
    
    for i in range(n_samples):
        t = i / sample_rate
        chord_idx = min(int(t / 4.0), len(chords) - 1)
        freqs = chords[chord_idx]
        
        # Synth Pad / Warm Sine tone
        pad = 0.0
        for f in freqs:
            pad += 0.20 * math.sin(2.0 * math.pi * f * t)
            # Add upper shimmer
            pad += 0.08 * math.sin(2.0 * math.pi * (f * 2.0) * t)
            
        # Subtle sub bass
        sub_freq = freqs[0] / 2.0
        bass = 0.30 * math.sin(2.0 * math.pi * sub_freq * t)
        
        # Rhythmic tech pulse (kick / percussion envelope on every beat)
        beat_phase = (t % beat_interval) / beat_interval
        drum_env = math.exp(-beat_phase * 16.0)
        drum = 0.35 * math.sin(2.0 * math.pi * 65.0 * (1.0 - beat_phase * 0.7) * (beat_phase * beat_interval)) * drum_env
        
        # Soft hi-hat tick on 8th notes
        sub_beat_phase = (t % (beat_interval / 2.0)) / (beat_interval / 2.0)
        hihat_env = math.exp(-sub_beat_phase * 35.0)
        # Pseudo white noise for hi-hat
        noise = (math.sin(t * 123456.7) % 1.0) - 0.5
        hihat = 0.08 * noise * hihat_env
        
        sample_val = pad + bass + drum + hihat
        
        # Master fade in (0-1s) and fade out (14.5-16s)
        if t < 1.0:
            sample_val *= t
        elif t > 14.5:
            sample_val *= max(0.0, (16.0 - t) / 1.5)
            
        # Clamp to 16-bit PCM
        sample_int = int(max(-1.0, min(1.0, sample_val * 0.75)) * 32767)
        # Stereo left and right
        frames += struct.pack('<hh', sample_int, sample_int)
        
    wav_file.writeframes(frames)
    wav_file.close()
    print("Audio soundtrack generated successfully:", output_wav)

def build_promo_video():
    img1 = os.path.abspath("promo/01_hero.jpg")
    img2 = os.path.abspath("promo/02_features.jpg")
    img3 = os.path.abspath("promo/03_offline_field.jpg")
    img4 = os.path.abspath("promo/04_outro.jpg")
    audio_wav = os.path.abspath("promo/music.wav")
    output_mp4 = os.path.abspath("promo/fieldplus_promo_video.mp4")
    
    generate_ambient_audio(audio_wav, duration_sec=16)
    
    # Build 1080p 60fps cinematic video with smooth pan/zoom (Ken Burns effect) and cross-fades
    # 4 scenes, 4 seconds each with crossfades
    ffmpeg_cmd = [
        "ffmpeg", "-y",
        "-loop", "1", "-t", "4.5", "-i", img1,
        "-loop", "1", "-t", "4.5", "-i", img2,
        "-loop", "1", "-t", "4.5", "-i", img3,
        "-loop", "1", "-t", "4.5", "-i", img4,
        "-i", audio_wav,
        "-filter_complex",
        (
            # Scene 1: Smooth subtle slow zoom in
            "[0:v]scale=1920:1080:force_original_aspect_ratio=increase,crop=1920:1080,"
            "zoompan=z='min(zoom+0.0006,1.08)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=135:s=1920x1080:fps=30,trim=duration=4,setpts=PTS-STARTPTS[v0];"
            
            # Scene 2: Subtle slow zoom out
            "[1:v]scale=1920:1080:force_original_aspect_ratio=increase,crop=1920:1080,"
            "zoompan=z='max(1.08-0.0006*on,1.0)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=135:s=1920x1080:fps=30,trim=duration=4,setpts=PTS-STARTPTS[v1];"
            
            # Scene 3: Smooth pan
            "[2:v]scale=1920:1080:force_original_aspect_ratio=increase,crop=1920:1080,"
            "zoompan=z='min(zoom+0.0005,1.06)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=135:s=1920x1080:fps=30,trim=duration=4,setpts=PTS-STARTPTS[v2];"
            
            # Scene 4: Outro focus zoom
            "[3:v]scale=1920:1080:force_original_aspect_ratio=increase,crop=1920:1080,"
            "zoompan=z='min(zoom+0.0004,1.05)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=135:s=1920x1080:fps=30,trim=duration=4,setpts=PTS-STARTPTS[v3];"
            
            # Cross-fade transitions (0.6s crossfade between clips)
            "[v0][v1]xfade=transition=fade:duration=0.6:offset=3.4[v01];"
            "[v01][v2]xfade=transition=fade:duration=0.6:offset=6.8[v012];"
            "[v012][v3]xfade=transition=fade:duration=0.6:offset=10.2[vfinal]"
        ),
        "-map", "[vfinal]",
        "-map", "4:a",
        "-c:v", "libx264",
        "-preset", "medium",
        "-crf", "18",
        "-pix_fmt", "yuv420p",
        "-c:a", "aac",
        "-b:a", "192k",
        "-shortest",
        output_mp4
    ]
    
    print("Executing ffmpeg render...")
    result = subprocess.run(ffmpeg_cmd, capture_output=True, text=True)
    if result.returncode != 0:
        print("FFmpeg error:", result.stderr)
    else:
        print("Promo video successfully created:", output_mp4)

if __name__ == "__main__":
    build_promo_video()
