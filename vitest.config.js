import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import { svelteTesting } from '@testing-library/svelte/vite';

export default defineConfig({
    plugins: [react(), svelte(), svelteTesting()],
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.{js,jsx}'],
    },
});
