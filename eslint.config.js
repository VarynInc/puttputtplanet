/* eslint rules https://eslint.org/docs/latest/rules/ */
export default [
    {
        files: ["source/**/*.js"],
        ignores: ["**/*.test.js"],
        rules: {
            semi: "error",
            quotes: ["error", "double", { "allowTemplateLiterals": true }],
            "prefer-const": "error"
        }
    }
];
